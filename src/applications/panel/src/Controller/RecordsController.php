<?php
namespace Panel\Controller;

use Panel\Audit;
use Panel\Core\Events;
use Panel\Core\Files;
use Panel\Core\Links;
use Panel\Invoicing\QrSvg;
use Panel\PanelContext;
use WebApplication\Csrf;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Session;

/**
 * Core services of every record (docs/panel-architektura.md chapter 4): short link /r/<ref> + QR code and label,
 * comments with @mentions, links between records, the feed "Čo sa deje". Any logged-in employee — but only for records
 * he may see (Core\Refs::resolve); a record he may not see is "not found".
 */
class RecordsController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    private function notFound(): Response
    {
        return Response::html($this->ctx->page('notfound', [], 'Záznam neexistuje alebo k nemu nemáte prístup'), 404);
    }

    /** Back to the record page (+ anchor), or the dashboard when the record is gone. */
    private function back(?array $record, string $anchor = ''): Response
    {
        return Response::redirect($this->ctx->url($record ? $record['url'] : '/') . $anchor);
    }

    // ------------------------------------------------------------------ short link, QR

    /** GET /r/{ref} — opens the record (QR codes on labels, links in e-mails and comments). */
    public function open(Request $req, array $params): Response
    {
        $r = $this->ctx->refs()->resolve((string)$params['ref']);
        return $r ? Response::redirect($this->ctx->url($r['url'])) : $this->notFound();
    }

    /** GET /r/{ref}/qr — QR code (SVG) of the short link. */
    public function qr(Request $req, array $params): Response
    {
        $ref = (string)$params['ref'];
        if (!$this->ctx->refs()->canSee($ref)) {
            return $this->notFound();
        }
        return new Response(QrSvg::svg($this->ctx->refs()->shortUrl($ref), 'M'), 200,
            ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=86400']);
    }

    /** GET /r/{ref}/label — printable label: QR + name + short link (box, device, rented item …). */
    public function label(Request $req, array $params): Response
    {
        $ref = (string)$params['ref'];
        $r = $this->ctx->refs()->resolve($ref);
        if ($r === null) {
            return $this->notFound();
        }
        return Response::html($this->ctx->view->render('core/label', ['r' => $r, 'url' => $this->ctx->refs()->shortUrl($ref),
            'qr' => QrSvg::dataUri($this->ctx->refs()->shortUrl($ref), 'M')]));
    }

    // ------------------------------------------------------------------ comments

    public function commentAdd(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $ref = (string)$req->post('ref', '');
        $record = $this->ctx->refs()->resolve($ref);
        if ($record === null) {
            return $this->notFound();
        }
        $body = \Panel\Core\Comments::clean((string)$req->post('body', ''));
        if ($body === '') {
            Session::flash('error', 'Komentár je prázdny — napíšte text.');
            return $this->back($record, '#comments');
        }
        $participants = $this->ctx->comments()->participants($ref);   // before this comment
        $id = $this->ctx->comments()->add($ref, $body, $this->ctx->user());
        Audit::log($this->ctx->db(), $this->ctx->user(), 'comment', $id, 'comment', mb_substr($body, 0, 120, 'UTF-8'), $ref);
        Events::emit($this->ctx, 'comment.added', ['ref' => $ref, 'actor' => $this->ctx->user(), 'record' => $record, 'comment_id' => $id,
            'body' => $body, 'mentions' => $this->ctx->comments()->mentionedIn($body), 'thread' => (bool)$participants]);
        Session::flash('msg', 'Komentár pridaný.');
        return $this->back($record, '#comment-' . $id);
    }

    /** The comment + its record, if the person may change it (author or Správca). */
    private function ownComment(Request $req, array $params): array
    {
        if (!Csrf::check($req)) {
            return [Response::text('Formulár vypršal, načítajte stránku znova.', 400), null, null];
        }
        $c = $this->ctx->comments()->find((int)$params['id']);
        $record = $c ? $this->ctx->refs()->resolve($c['ref']) : null;
        if ($c === null || $record === null || $c['deleted_at'] !== null) {
            return [$this->notFound(), null, null];
        }
        if ((int)$c['client_id'] !== $this->ctx->user()->getId() && !$this->ctx->isAdmin()) {
            Session::flash('error', 'Upraviť alebo zmazať môže komentár len jeho autor.');
            return [$this->back($record, '#comment-' . $c['id']), null, null];
        }
        return [null, $c, $record];
    }

    public function commentEdit(Request $req, array $params): Response
    {
        list($stop, $c, $record) = $this->ownComment($req, $params);
        if ($stop) {
            return $stop;
        }
        $body = \Panel\Core\Comments::clean((string)$req->post('body', ''));
        if ($body === '') {
            Session::flash('error', 'Komentár je prázdny — ak ho nechcete, zmažte ho.');
            return $this->back($record, '#comment-' . $c['id']);
        }
        $before = $this->ctx->comments()->mentionedIn((string)$c['body']);
        $this->ctx->comments()->update((int)$c['id'], $body);
        $new = array_values(array_diff($this->ctx->comments()->mentionedIn($body), $before));
        if ($new) {   // people mentioned by the edit hear about it; the discussion does not get a second notice
            Events::emit($this->ctx, 'comment.edited', ['ref' => $c['ref'], 'actor' => $this->ctx->user(), 'record' => $record,
                'comment_id' => (int)$c['id'], 'body' => $body, 'mentions' => $new, 'thread' => false]);
        }
        Session::flash('msg', 'Komentár upravený.');
        return $this->back($record, '#comment-' . $c['id']);
    }

    public function commentDelete(Request $req, array $params): Response
    {
        list($stop, $c, $record) = $this->ownComment($req, $params);
        if ($stop) {
            return $stop;
        }
        $this->ctx->comments()->delete((int)$c['id']);
        Session::flash('msg', 'Komentár zmazaný.');
        return $this->back($record, '#comments');
    }

    // ------------------------------------------------------------------ links

    /** GET /links/lookup?q= — records the person may see, for the "Prepojiť" field (JSON). */
    public function lookup(Request $req, array $params): Response
    {
        $out = [];
        foreach ($this->ctx->refs()->find((string)$req->query('q', '')) as $r) {
            $out[] = ['ref' => $r['ref'], 'title' => $r['title'], 'label' => $r['label']];
        }
        return Response::json($out);
    }

    public function linkAdd(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $ref = (string)$req->post('ref', '');
        $record = $this->ctx->refs()->resolve($ref);
        if ($record === null) {
            return $this->notFound();
        }
        $kind = (string)$req->post('kind', 'relates');
        $target = trim((string)$req->post('target_ref', '')) ?: trim((string)$req->post('target', ''));
        $found = $this->ctx->refs()->find($target);
        $found = array_values(array_filter($found, function ($r) use ($ref) { return $r['ref'] !== $ref; }));
        if (!isset(Links::KINDS[$kind])) {
            Session::flash('error', 'Neznámy druh prepojenia.');
        } elseif (!$found) {
            Session::flash('error', 'Záznam „' . $target . '“ sa nenašiel (alebo k nemu nemáte prístup). Napíšte číslo faktúry, objednávky, dokladu'
                . ' alebo názov a vyberte zo zoznamu, prípadne vložte krátky odkaz záznamu (…/r/…).');
        } elseif (count($found) > 1) {
            Session::flash('error', 'Podľa „' . $target . '“ sa našlo viac záznamov: ' . implode(', ', array_map(function ($r) {
                return $r['label'] . ' ' . $r['title']; }, $found)) . '. Spresnite text alebo vyberte zo zoznamu.');
        } else {
            $other = $found[0];
            $id = $this->ctx->links()->add($ref, $other['ref'], $kind, $this->ctx->user());
            if ($id === null) {
                Session::flash('error', 'Toto prepojenie už existuje.');
            } else {
                $label = Links::KINDS[$kind];
                Audit::log($this->ctx->db(), $this->ctx->user(), 'link', $id, 'link', $label[0] . ' ' . $other['label'] . ' ' . $other['title'], $ref);
                Audit::log($this->ctx->db(), $this->ctx->user(), 'link', $id, 'link', $label[1] . ' ' . $record['label'] . ' ' . $record['title'], $other['ref']);
                Events::emit($this->ctx, 'link.added', ['ref' => $ref, 'actor' => $this->ctx->user(), 'record' => $record, 'other' => $other,
                    'kind' => $kind, 'link_id' => $id]);
                Session::flash('msg', 'Prepojené: ' . $other['label'] . ' ' . $other['title'] . '.');
            }
        }
        return $this->back($record, '#links');
    }

    public function linkDelete(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $l = $this->ctx->links()->find((int)$params['id']);
        $from = $l ? $this->ctx->refs()->resolve($l['from_ref']) : null;
        $to = $l ? $this->ctx->refs()->resolve($l['to_ref']) : null;
        if ($l === null || $from === null || $to === null) {   // both sides must be visible
            return $this->notFound();
        }
        $back = (string)$req->post('ref', '') === $l['to_ref'] ? $to : $from;
        $this->ctx->links()->delete((int)$l['id']);
        Audit::log($this->ctx->db(), $this->ctx->user(), 'link', (int)$l['id'], 'unlink', $to['label'] . ' ' . $to['title'], $l['from_ref']);
        Audit::log($this->ctx->db(), $this->ctx->user(), 'link', (int)$l['id'], 'unlink', $from['label'] . ' ' . $from['title'], $l['to_ref']);
        Session::flash('msg', 'Prepojenie zrušené.');
        return $this->back($back, '#links');
    }

    // ------------------------------------------------------------------ files (Nextcloud, docs/panel-architektura.md 4.1)

    /** The record + the files service, or a Response when not allowed / Nextcloud not set up. */
    private function filesFor(string $ref): array
    {
        $record = $this->ctx->refs()->resolve($ref);
        if ($record === null) {
            return [$this->notFound(), null, null];
        }
        $files = new Files($this->ctx);
        if (!$files->configured()) {
            return [Response::text('Súbory nie sú nastavené (Nastavenia → Systém → Integrácie → Nextcloud).', 404), null, null];
        }
        return [null, $record, $files];
    }

    /** GET /files/{ref} — the "Súbory" part of the record box (loaded by js/panel.js, so a slow Nextcloud never blocks the page). */
    public function files(Request $req, array $params): Response
    {
        list($stop, $record, $files) = $this->filesFor((string)$params['ref']);
        if ($stop) {
            return $stop;
        }
        $error = '';
        $list = [];
        try {
            $list = $files->listFiles($record);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
        return Response::html($this->ctx->view->render('core/files', ['record' => $record, 'files' => $list, 'error' => $error,
            'shares' => $files->shares($record['ref']), 'limit' => Files::uploadLimit(),
            'webUrl' => $this->ctx->isAdmin() && ($folder = $files->folder($record, false)) ? $files->client()->webUrl($folder) : '']));
    }

    public function filesUpload(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        list($stop, $record, $files) = $this->filesFor((string)$params['ref']);
        if ($stop) {
            return $stop;
        }
        $list = $req->files('files');   // <input name="files[]" multiple>
        $done = [];
        $errors = [];
        foreach ($list as $f) {
            if ($f['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                $errors[] = $f['name'] . ' (' . (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'je väčší ako ' . round(Files::uploadLimit() / 1048576) . ' MB — nahrajte ho priamo do Nextcloudu alebo cez odkaz na nahratie' : 'chyba nahrávania') . ')';
                continue;
            }
            try {
                $done[] = $files->upload($record, (string)$f['name'], (string)file_get_contents($f['tmp_name']));
            } catch (\RuntimeException $e) {
                $errors[] = $f['name'] . ' (' . $e->getMessage() . ')';
            }
        }
        if ($done) {
            Audit::log($this->ctx->db(), $this->ctx->user(), 'file', 0, 'file', implode(', ', $done), $record['ref']);
        }
        if (!$done && !$errors) {
            $errors[] = 'vyberte súbor';
        }
        Session::flash($errors ? 'error' : 'msg', ($done ? 'Nahraté: ' . implode(', ', $done) . '. ' : '') . ($errors ? 'Nenahraté: ' . implode('; ', $errors) . '.' : ''));
        return $this->back($record, '#files');
    }

    public function filesDownload(Request $req, array $params): Response
    {
        list($stop, $record, $files) = $this->filesFor((string)$params['ref']);
        if ($stop) {
            return $stop;
        }
        $name = (string)$req->query('name', '');
        try {
            $bytes = $files->download($record, $name);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            return $this->back($record, '#files');
        }
        return new Response($bytes, 200, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => "attachment; filename*=UTF-8''" . rawurlencode($name), 'Cache-Control' => 'private, no-store']);
    }

    public function filesDelete(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        list($stop, $record, $files) = $this->filesFor((string)$params['ref']);
        if ($stop) {
            return $stop;
        }
        $name = (string)$req->post('name', '');
        try {
            $files->delete($record, $name);
            Audit::log($this->ctx->db(), $this->ctx->user(), 'file', 0, 'file_delete', $name, $record['ref']);
            Session::flash('msg', 'Súbor „' . $name . '“ zmazaný (v Nextcloude ostáva v koši).');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }
        return $this->back($record, '#files');
    }

    public function filesShare(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        list($stop, $record, $files) = $this->filesFor((string)$params['ref']);
        if ($stop) {
            return $stop;
        }
        $kind = (string)$req->post('kind', 'read');
        try {
            $s = $files->share($record, $kind, (string)$req->post('password', ''), (int)$req->post('days', 14));
            Audit::log($this->ctx->db(), $this->ctx->user(), 'file', (int)$s['id'], 'share',
                ($kind === 'upload' ? 'odkaz na nahratie' : 'odkaz na stiahnutie') . ($s['expires'] ? ' do ' . $s['expires'] : '') . ($s['has_password'] ? ', s heslom' : ''),
                $record['ref']);
            Session::flash('msg', 'Odkaz vytvorený: ' . $s['url']);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        }
        return $this->back($record, '#files');
    }

    public function filesRevoke(Request $req, array $params): Response
    {
        if (!Csrf::check($req)) {
            return Response::text('Formulár vypršal, načítajte stránku znova.', 400);
        }
        $files = new Files($this->ctx);
        $s = $files->findShare((int)$params['id']);
        $record = $s ? $this->ctx->refs()->resolve($s['ref']) : null;
        if ($s === null || $record === null || $s['revoked_at'] !== null || !$files->configured()) {
            return $this->notFound();
        }
        try {
            $files->revoke($s);
            Audit::log($this->ctx->db(), $this->ctx->user(), 'file', (int)$s['id'], 'unshare', $s['url'], $record['ref']);
            Session::flash('msg', 'Odkaz zrušený — už nefunguje.');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }
        return $this->back($record, '#files');
    }

    // ------------------------------------------------------------------ search

    /** GET /search?q= — one field on every page, across all records the person may see. */
    public function search(Request $req, array $params): string
    {
        $q = trim((string)$req->query('q', ''));
        $groups = [];
        foreach ($q === '' ? [] : $this->ctx->refs()->find($q, 60) as $r) {
            $groups[$r['label']][] = $r;
        }
        return $this->ctx->page('core/search', ['q' => $q, 'groups' => $groups], $q === '' ? 'Hľadať' : 'Hľadať: ' . $q, 'search');
    }

    // ------------------------------------------------------------------ feed

    /** GET /activity — "Čo sa deje": newest changes of the records the person may see. */
    public function feed(Request $req, array $params): string
    {
        $page = $this->ctx->activity()->feed($this->ctx->refs(), max(0, (int)$req->query('before', 0)));
        return $this->ctx->page('core/activity', ['rows' => $page['rows'], 'next' => $page['next'], 'first' => !$req->query('before')],
            'Čo sa deje', 'activity');
    }
}
