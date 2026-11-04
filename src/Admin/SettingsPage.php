<?php

declare(strict_types=1);

namespace Rezos\Admin;

use Rezos\Api\GraphException;
use Rezos\Cache\FeedCache;
use Rezos\Plugin;

/**
 * Réglages > Rezos : identifiants de l'app Meta, connexion de la Page, cache.
 */
final class SettingsPage
{
    public const SLUG = 'rezos';
    private const CAP = 'manage_options';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_options_page('Rezos', 'Rezos', self::CAP, self::SLUG, [$this, 'render']);
        });

        foreach (['save', 'connect', 'select_page', 'test', 'flush', 'disconnect'] as $action) {
            add_action('admin_post_rezos_' . $action, function () use ($action): void {
                $this->guard($action);
                $method = 'handle' . str_replace('_', '', ucwords($action, '_'));
                $this->{$method}();
            });
        }

        add_filter('plugin_action_links_' . plugin_basename(REZOS_FILE), static function (array $links): array {
            array_unshift($links, sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('options-general.php?page=' . self::SLUG)),
                esc_html__('Réglages', 'rezos'),
            ));

            return $links;
        });
    }

    public function render(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }

        $notice = get_transient($this->noticeKey());
        delete_transient($this->noticeKey());

        echo $this->plugin->twig()->render('admin/settings.html.twig', [
            'settings'     => $this->plugin->settings()->all(),
            'connected'    => $this->plugin->settings()->isConnected(),
            'pending'      => get_transient($this->pendingKey()) ?: [],
            'notice'       => is_array($notice) ? $notice : null,
            'post_url'     => admin_url('admin-post.php'),
            'redirect_uri' => home_url('/'),
        ]);
    }

    private function handleSave(): void
    {
        $in = wp_unslash($_POST);
        $values = [
            'app_id'        => preg_replace('/\D/', '', (string) ($in['app_id'] ?? '')),
            'graph_version' => preg_match('/^v\d+\.\d+$/', (string) ($in['graph_version'] ?? '')) ? $in['graph_version'] : 'v25.0',
            'cache_ttl'     => max(300, (int) ($in['cache_ttl'] ?? 3600)),
            'default_limit' => max(1, min(50, (int) ($in['default_limit'] ?? 9))),
        ];
        // Champ secret laissé vide = conserver la valeur actuelle.
        $secret = trim((string) ($in['app_secret'] ?? ''));
        if ($secret !== '') {
            $values['app_secret'] = sanitize_text_field($secret);
        }

        $this->plugin->settings()->update($values);
        FeedCache::flushAll();
        $this->redirect('success', __('Réglages enregistrés.', 'rezos'));
    }

    /** Étape 1 : token utilisateur court → token long → liste des Pages. */
    private function handleConnect(): void
    {
        $settings = $this->plugin->settings();
        $appId = (string) $settings->get('app_id');
        $secret = (string) $settings->get('app_secret');
        $token = trim(sanitize_text_field(wp_unslash((string) ($_POST['user_token'] ?? ''))));

        if ($appId === '' || $secret === '' || $token === '') {
            $this->redirect('error', __("Renseignez l'App ID, l'App Secret et un token utilisateur.", 'rezos'));
        }

        try {
            $graph = $this->plugin->graph();
            $longToken = $graph->exchangeForLongLivedUserToken($appId, $secret, $token);
            $pages = $graph->listPages($longToken, $secret);
        } catch (GraphException $e) {
            $this->redirect('error', $e->getMessage());
        }

        if ($pages === []) {
            $this->redirect('error', __('Aucune Page trouvée. Vérifiez les permissions pages_show_list et pages_read_engagement.', 'rezos'));
        }

        if (count($pages) === 1) {
            $this->savePage($pages[0]);
        }

        set_transient($this->pendingKey(), $pages, 10 * MINUTE_IN_SECONDS);
        $this->redirect('info', __('Choisissez la Page à afficher.', 'rezos'));
    }

    /** Étape 2 (si plusieurs Pages) : choix de la Page. */
    private function handleSelectPage(): void
    {
        $pages = get_transient($this->pendingKey()) ?: [];
        $id = (string) ($_POST['page_id'] ?? '');
        foreach ($pages as $page) {
            if ($page['id'] === $id) {
                $this->savePage($page);
            }
        }
        $this->redirect('error', __('Page introuvable, recommencez la connexion.', 'rezos'));
    }

    private function handleTest(): void
    {
        $feed = $this->plugin->feed();
        FeedCache::flushAll();
        $counts = [];
        foreach ($feed->availableSources() as $source) {
            $counts[] = sprintf('%s : %d', $source, count($feed->getPosts([$source], 50)));
        }
        $errors = $feed->lastErrors();

        $errors === []
            ? $this->redirect('success', __('Connexion OK — ', 'rezos') . implode(', ', $counts))
            : $this->redirect('error', implode(' | ', $errors));
    }

    private function handleFlush(): void
    {
        FeedCache::flushAll();
        $this->redirect('success', __('Cache vidé.', 'rezos'));
    }

    private function handleDisconnect(): void
    {
        $this->plugin->settings()->update([
            'page_id' => '', 'page_name' => '', 'page_token' => '', 'ig_user_id' => '', 'ig_username' => '',
        ]);
        FeedCache::flushAll();
        $this->redirect('success', __('Page déconnectée.', 'rezos'));
    }

    /** @param array{id: string, name: string, access_token: string, ig_id: ?string, ig_username: ?string} $page */
    private function savePage(array $page): never
    {
        $this->plugin->settings()->update([
            'page_id'     => $page['id'],
            'page_name'   => $page['name'],
            'page_token'  => $page['access_token'],
            'ig_user_id'  => $page['ig_id'] ?? '',
            'ig_username' => $page['ig_username'] ?? '',
        ]);
        delete_transient($this->pendingKey());
        FeedCache::flushAll();

        $msg = sprintf(__('Page « %s » connectée.', 'rezos'), $page['name']);
        $msg .= $page['ig_id']
            ? ' ' . sprintf(__('Instagram : @%s.', 'rezos'), $page['ig_username'])
            : ' ' . __("Aucun compte Instagram professionnel n'est lié à cette Page.", 'rezos');
        $this->redirect('success', $msg);
    }

    private function guard(string $action): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Accès refusé.', 'rezos'), 403);
        }
        check_admin_referer('rezos_' . $action);
    }

    private function redirect(string $type, string $message): never
    {
        set_transient($this->noticeKey(), ['type' => $type, 'message' => $message], 60);
        wp_safe_redirect(admin_url('options-general.php?page=' . self::SLUG));
        exit;
    }

    private function noticeKey(): string
    {
        return 'rezos_notice_' . get_current_user_id();
    }

    private function pendingKey(): string
    {
        return 'rezos_pending_pages_' . get_current_user_id();
    }
}
