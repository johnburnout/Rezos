<?php

declare(strict_types=1);

namespace Rezos\Admin;

use Rezos\Api\GraphException;
use Rezos\Cache\FeedCache;
use Rezos\Front\FeedRenderer;
use Rezos\Front\Shortcode;
use Rezos\Model\Account;
use Rezos\Plugin;
use Rezos\Settings;

/**
 * Réglages > Rezos : identifiants de l'app Meta, connexion des Pages, cache.
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

        // Styles du flux pour l'aperçu (le style est enregistré par Plugin::boot sur ce même hook).
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            if ($hook === 'settings_page_' . self::SLUG) {
                wp_enqueue_style('rezos');
            }
        });

        foreach (['save', 'connect', 'select_pages', 'remove_page', 'test', 'flush', 'disconnect'] as $action) {
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
            ...$this->preview(),
            'settings'     => $this->plugin->settings()->all(),
            'accounts'     => $this->plugin->settings()->accounts(),
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
            'default_columns'  => max(1, min(Settings::MAX_COLUMNS, (int) ($in['default_columns'] ?? 3))),
            'default_rows'     => max(1, min(Settings::MAX_ROWS, (int) ($in['default_rows'] ?? 3))),
            'default_featured' => !empty($in['default_featured']),
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

    /** Étape 1 : token utilisateur court → token long → liste des Pages accessibles. */
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
            $this->savePages($pages, $pages);
        }

        set_transient($this->pendingKey(), $pages, 10 * MINUTE_IN_SECONDS);
        $this->redirect('info', __('Choisissez les Pages à afficher.', 'rezos'));
    }

    /** Étape 2 (si plusieurs Pages) : choix des Pages. */
    private function handleSelectPages(): void
    {
        $pages = get_transient($this->pendingKey());
        if (!is_array($pages) || $pages === []) {
            $this->redirect('error', __('La sélection a expiré, recommencez la connexion.', 'rezos'));
        }

        $ids = array_map('strval', (array) wp_unslash($_POST['page_ids'] ?? []));
        $selected = array_values(array_filter($pages, static fn (array $p): bool => in_array($p['id'], $ids, true)));

        $this->savePages($selected, $pages);
    }

    private function handleRemovePage(): void
    {
        $settings = $this->plugin->settings();
        $id = (string) wp_unslash($_POST['page_id'] ?? '');
        $account = $settings->account($id);
        if ($account === null) {
            $this->redirect('error', __('Page introuvable.', 'rezos'));
        }

        $settings->saveAccounts(array_values(array_filter(
            $settings->accounts(),
            static fn (Account $a): bool => $a->id !== $id,
        )));
        FeedCache::flushAll();
        $this->redirect('success', sprintf(__('Page « %s » retirée.', 'rezos'), $account->name));
    }

    private function handleTest(): void
    {
        $feed = $this->plugin->feed();
        FeedCache::flushAll();
        $counts = [];
        $errors = [];
        foreach ($this->plugin->settings()->accounts() as $account) {
            $parts = [];
            foreach ($feed->availableSources() as $source) {
                if ($feed->supports($source, $account)) {
                    $parts[] = sprintf('%s %d', $source, count($feed->getPosts([$source], 50, null, [$account->id])));
                    $errors = [...$errors, ...$feed->lastErrors()];
                }
            }
            $counts[] = sprintf('%s (%s)', $account->name, implode(', ', $parts));
        }

        $errors === []
            ? $this->redirect('success', __('Connexion OK — ', 'rezos') . implode(' ; ', $counts))
            : $this->redirect('error', implode(' | ', $errors));
    }

    private function handleFlush(): void
    {
        FeedCache::flushAll();
        $this->redirect('success', __('Cache vidé.', 'rezos'));
    }

    private function handleDisconnect(): void
    {
        $this->plugin->settings()->saveAccounts([]);
        delete_transient($this->pendingKey());
        FeedCache::flushAll();
        $this->redirect('success', __('Toutes les Pages ont été déconnectées.', 'rezos'));
    }

    /**
     * Aperçu du shortcode saisi dans la section « Aperçu » (paramètre GET « preview », lecture seule).
     *
     * @return array{preview_code: string, preview_html: ?string, preview_error: ?string}
     */
    private function preview(): array
    {
        $code = isset($_GET['preview']) ? trim(wp_unslash((string) $_GET['preview'])) : '';
        $result = ['preview_code' => $code !== '' ? $code : '[' . Shortcode::TAG . ']', 'preview_html' => null, 'preview_error' => null];
        if ($code === '' || !$this->plugin->settings()->isConnected()) {
            return $result;
        }

        $atts = Shortcode::parse($code);
        if ($atts === null) {
            $result['preview_error'] = __('Saisissez un shortcode de la forme [rezos …].', 'rezos');
        } else {
            $result['preview_html'] = (new FeedRenderer($this->plugin))->render($atts);
        }

        return $result;
    }

    /**
     * Enregistre la sélection. Les Pages proposées mais non cochées sont retirées ;
     * celles connectées avec un autre compte Facebook (absentes de la liste proposée) sont conservées.
     *
     * @param list<array{id: string, name: string, access_token: string, ig_id: ?string, ig_username: ?string}> $selected
     * @param list<array{id: string, name: string, access_token: string, ig_id: ?string, ig_username: ?string}> $offered
     */
    private function savePages(array $selected, array $offered): never
    {
        $settings = $this->plugin->settings();
        $offeredIds = array_column($offered, 'id');

        $accounts = array_values(array_filter(
            $settings->accounts(),
            static fn (Account $a): bool => !in_array($a->id, $offeredIds, true),
        ));
        foreach ($selected as $page) {
            $accounts[] = Account::fromArray($page);
        }

        $settings->saveAccounts($accounts);
        delete_transient($this->pendingKey());
        FeedCache::flushAll();

        if ($selected === []) {
            $this->redirect('info', __('Aucune Page sélectionnée.', 'rezos'));
        }

        $names = array_map(
            static fn (array $p): string => $p['name'] . ($p['ig_username'] ? ' (@' . $p['ig_username'] . ')' : ''),
            $selected,
        );
        $this->redirect('success', sprintf(
            _n('Page connectée : %s.', 'Pages connectées : %s.', count($selected), 'rezos'),
            implode(', ', $names),
        ));
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
