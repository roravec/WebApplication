<?php
namespace Panel\Modules;

use Panel\Controller\ApiController;
use Panel\Controller\SystemController;
use Panel\Core\Notifications;
use Panel\Core\ProvidesJobs;
use Panel\Core\ProvidesListeners;
use Panel\Module;
use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Http\Request;
use WebApplication\Http\Router;

/**
 * Core services without a record (docs/panel-architektura.md chapter 4): scheduler (cron URL, tick, Nastavenia →
 * Systém), notifications (bell, list, e-mail digest) and who is notified about what (listeners).
 */
class SystemModule implements Module, ProvidesJobs, ProvidesListeners
{
    public function routes(Router $r, callable $guard, PanelContext $ctx): void
    {
        $c = new SystemController($ctx);
        $r->get('/cron', [$c, 'cron']);              // token in the URL (config cron_token), no login
        $r->get('/cron/accounting', [$c, 'cron']);   // older URL (before 2026-11-21 only the accountant's package)
        $r->post('/cron/tick', $guard('', [$c, 'tick']));
        $r->get('/settings/system', $guard(Rights::SETTINGS_SYSTEM, [$c, 'settings']));
        $r->post('/settings/system/run/{key:[a-z_.]+}', $guard(Rights::SETTINGS_SYSTEM, [$c, 'runJob']));
        $r->get('/settings/integrations', $guard(Rights::SETTINGS_SYSTEM, [$c, 'integrations']));
        $r->post('/settings/integrations/{name:[a-z]+}', $guard(Rights::SETTINGS_SYSTEM, [$c, 'integrationSave']));
        $r->post('/settings/integrations/{name:[a-z]+}/check', $guard(Rights::SETTINGS_SYSTEM, [$c, 'integrationCheck']));
        $r->get('/calendar', $guard('', [$c, 'calendar']));

        // API (Bearer key, no session) + key management
        $api = new ApiController($ctx);
        $r->get('/api/v1/me', $api->guard([$api, 'me']));
        $r->get('/api/v1/search', $api->guard([$api, 'search']));
        $r->get('/api/v1/activity', $api->guard([$api, 'activity']));
        $r->get('/api/v1/records/' . RecordsModule::REF, $api->guard([$api, 'record']));
        $r->get('/api/v1/records/' . RecordsModule::REF . '/comments', $api->guard([$api, 'comments']));
        $r->post('/api/v1/records/' . RecordsModule::REF . '/comments', $api->guard([$api, 'commentAdd']));
        $r->post('/account/api-keys', $guard(Rights::API_KEYS, [$api, 'create']));
        $r->post('/account/api-keys/{id:\d+}/revoke', $guard('', [$api, 'revoke']));
        $r->get('/settings/api-keys', $guard(Rights::SETTINGS_SYSTEM, [$api, 'overview']));
        $r->post('/settings/api-keys/{id:\d+}/revoke', $guard(Rights::SETTINGS_SYSTEM, function (Request $req, array $p) use ($api) {
            return $api->revoke($req, $p + ['scope' => 'all']);
        }));
        $r->get('/notifications', $guard('', [$c, 'notificationsList']));
        $r->get('/notifications/count', $guard('', [$c, 'notificationsCount']));
        $r->get('/notifications/{id:\d+}', $guard('', [$c, 'notificationOpen']));
        $r->post('/notifications/read-all', $guard('', [$c, 'notificationsReadAll']));
        $r->post('/account/notifications', $guard('', [$c, 'notifyPrefSave']));
    }

    public function nav(): array
    {
        return [['key' => 'calendar', 'group' => 'work', 'path' => '/calendar', 'label' => 'Kalendár', 'permission' => '', 'order' => 50]];
    }

    public function settingsTabs(): array
    {
        return [['key' => 'system', 'path' => '/settings/system', 'label' => 'Systém', 'permission' => Rights::SETTINGS_SYSTEM, 'order' => 70]];
    }

    public function permissions(): array
    {
        return [
            ['key' => Rights::SETTINGS_SYSTEM, 'group' => 'Nastavenia', 'sensitive' => true,
                'label' => 'Nastavenia → Systém — plánovač úloh, integrácie (Nextcloud …), prehľad a rušenie všetkých API kľúčov'],
            ['key' => Rights::API_KEYS, 'group' => 'API', 'sensitive' => true,
                'label' => 'API — vlastné API kľúče (testovacie prípravky, CI, skripty) v rozsahu vlastných práv'],
        ];
    }

    public function recordTypes(): array
    {
        return [];
    }

    public function jobs(): array
    {
        return [
            ['key' => 'notifications.email', 'label' => 'Upozornenia e-mailom (hneď)', 'every' => 1,
                'run' => function (PanelContext $sys): string { return $sys->notifications()->sendPending($sys, $sys->mailer()); }],
            ['key' => 'notifications.digest', 'label' => 'Denný súhrn upozornení', 'every' => 1440, 'hour' => Notifications::DIGEST_HOUR,
                'run' => function (PanelContext $sys): string { return $sys->notifications()->sendDigest($sys, $sys->mailer()); }],
        ];
    }

    /**
     * Who is notified: comment.added / comment.edited (data: ref, actor, record, body, mentions = ids of newly mentioned
     * people, thread = also the earlier participants of the discussion).
     */
    public function listeners(): array
    {
        $comment = function (PanelContext $ctx, array $d): void {
            $actor = $d['actor'];
            $what = $d['record']['label'] . ' ' . $d['record']['title'];
            $excerpt = '„' . mb_substr(preg_replace('/\s+/u', ' ', (string)$d['body']), 0, 120, 'UTF-8') . '“';
            $done = [$actor ? $actor->getId() : 0 => true];
            $n = $ctx->notifications();
            foreach ($d['mentions'] as $id) {
                if (empty($done[$id])) {
                    $n->notify($ctx, (int)$id, $d['ref'], 'mention', ($actor ? $actor->getName() : 'Niekto') . ' vás spomenul — ' . $what . ': ' . $excerpt,
                        $actor ? $actor->getName() : '');
                    $done[$id] = true;
                }
            }
            if (!empty($d['thread'])) {   // the earlier participants of the discussion hear about a new comment
                foreach ($ctx->comments()->participants($d['ref']) as $id) {
                    if (empty($done[$id])) {
                        $n->notify($ctx, $id, $d['ref'], 'comment', ($actor ? $actor->getName() : 'Niekto') . ' pridal komentár — ' . $what . ': ' . $excerpt,
                            $actor ? $actor->getName() : '');
                        $done[$id] = true;
                    }
                }
            }
        };
        return [['comment.added', $comment], ['comment.edited', $comment]];
    }
}
