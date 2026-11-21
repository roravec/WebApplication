<?php
namespace Panel\Modules;

use Panel\Markdown;
use Panel\Module;
use Panel\PanelContext;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Http\Router;

/**
 * Pomocník: guides for the people who use the panel (docs/panel-architektura.md, chapter 7 — "aby v tom vedel robiť aj
 * idiot"). Guides are help/<slug>.md (simple Markdown, src/Markdown.php); help/_index.php says which guide belongs to
 * which page (template) or menu section and how the list of all guides is grouped. A changed feature = a changed guide.
 */
class HelpModule implements Module
{
    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/help';
    }

    /** ['groups' => [heading => [slug …]], 'pages' => [template => slug], 'sections' => [menu key => slug]] */
    public static function index(): array
    {
        return require self::dir() . '/_index.php';
    }

    /** Markdown of a guide or null. */
    public static function source(string $slug): ?string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return null;
        }
        $file = self::dir() . '/' . $slug . '.md';
        return is_file($file) ? (string)file_get_contents($file) : null;
    }

    /** The guide of a page: by its template, else by its menu section, else null. */
    public static function forPage(string $template, string $section): ?string
    {
        $idx = self::index();
        $slug = $idx['pages'][$template] ?? $idx['sections'][(string)strtok($section, ':')] ?? null;
        if ($slug === null && strpos($section, ':') !== false) {
            $slug = $idx['sections'][$section] ?? null;
        }
        return $slug !== null && self::source($slug) !== null ? $slug : null;
    }

    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $r->get('/help', $guard('', function () use ($ctx) {
            $groups = [];
            foreach (self::index()['groups'] as $label => $slugs) {
                foreach ($slugs as $slug) {
                    $md = self::source($slug);
                    if ($md !== null) {
                        $groups[$label][$slug] = Markdown::title($md) ?: $slug;
                    }
                }
            }
            return $ctx->page('help/index', ['groups' => $groups], 'Pomocník', 'help');
        }));
        $r->get('/help/for', $guard('', function (Request $req) use ($ctx) {
            $slug = self::forPage((string)$req->query('page', ''), (string)$req->query('section', ''));
            return Response::redirect($ctx->url($slug ? '/help/' . $slug : '/help'));
        }));
        $r->get('/help/{slug:[a-z0-9-]+}', $guard('', function (Request $req, array $p) use ($ctx) {
            $md = self::source($p['slug']);
            if ($md === null) {
                return Response::html($ctx->page('notfound', [], 'Návod neexistuje', 'help'), 404);
            }
            $title = Markdown::title($md);
            $md = preg_replace('/^#\s+.+$/m', '', $md, 1);   // the title is the page heading
            $html = Markdown::toHtml($md, function ($path) use ($ctx) { return $ctx->url($path); });
            return $ctx->page('help/page', ['html' => $html, 'slug' => $p['slug']], $title ?: 'Pomocník', 'help');
        }));
    }

    public function nav(): array
    {
        return [];
    }

    public function settingsTabs(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [];
    }

    public function recordTypes(): array
    {
        return [];
    }
}
