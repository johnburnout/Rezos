<?php

declare(strict_types=1);

namespace Rezos;

use Rezos\Admin\SettingsPage;
use Rezos\Admin\Updater;
use Rezos\Api\FacebookProvider;
use Rezos\Api\GraphClient;
use Rezos\Api\InstagramProvider;
use Rezos\Cache\FeedCache;
use Rezos\Feed\FeedService;
use Rezos\Front\FeedRenderer;
use Rezos\Front\Shortcode;
use Rezos\Front\Widget;
use Rezos\Twig\TwigFactory;
use Symfony\Component\HttpClient\HttpClient;
use Twig\Environment;

/**
 * Point d'entrée : construit les services et branche les hooks WordPress.
 */
final class Plugin
{
    /** Version des données enregistrées (réglages, cache), pour les migrations. */
    public const DB_VERSION_OPTION = 'rezos_db_version';

    private static ?self $instance = null;

    private ?Settings $settings = null;
    private ?GraphClient $graph = null;
    private ?FeedService $feed = null;
    private ?FeedRenderer $renderer = null;
    private ?Environment $twig = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        load_plugin_textdomain('rezos', false, dirname(plugin_basename(REZOS_FILE)) . '/languages');

        $this->maybeUpgrade();

        (new Shortcode($this))->register();

        // Toujours actif : WordPress vérifie aussi les mises à jour par le cron, hors administration.
        (new Updater())->register();

        add_action('widgets_init', static function (): void {
            register_widget(Widget::class);
        });

        $registerAssets = static function (): void {
            wp_register_style('rezos', REZOS_URL . 'assets/rezos.css', [], REZOS_VERSION);
            // Pagination du flux, chargé seulement si un flux a plusieurs pages.
            wp_register_script('rezos', REZOS_URL . 'assets/rezos.js', [], REZOS_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
        };
        add_action('wp_enqueue_scripts', $registerAssets);
        add_action('admin_enqueue_scripts', $registerAssets); // aperçu dans les réglages

        if (is_admin()) {
            (new SettingsPage($this))->register();
        }
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings();
    }

    public function graph(): GraphClient
    {
        return $this->graph ??= new GraphClient(
            HttpClient::create(['timeout' => 10]),
            (string) $this->settings()->get('graph_version'),
            (string) $this->settings()->get('app_secret'),
        );
    }

    public function feed(): FeedService
    {
        if ($this->feed === null) {
            $settings = $this->settings();
            $this->feed = new FeedService(
                [
                    'facebook'  => new FacebookProvider($this->graph()),
                    'instagram' => new InstagramProvider($this->graph()),
                ],
                new FeedCache((int) $settings->get('cache_ttl')),
                $settings,
            );
        }

        return $this->feed;
    }

    public function renderer(): FeedRenderer
    {
        return $this->renderer ??= new FeedRenderer($this);
    }

    public function twig(): Environment
    {
        return $this->twig ??= TwigFactory::create(REZOS_DIR . 'templates');
    }

    /**
     * Migrations des données, exécutées une seule fois après une mise à jour du plugin.
     */
    private function maybeUpgrade(): void
    {
        $installed = (string) get_option(self::DB_VERSION_OPTION, '0');
        if (version_compare($installed, REZOS_VERSION, '>=')) {
            return;
        }

        // Settings migre les anciens formats à la lecture ; on réenregistre pour supprimer
        // les anciennes clés : page_id, page_token… (0.1 → 0.2), default_limit (0.2 → 1.0).
        if (is_array(get_option(Settings::OPTION))) {
            $this->settings()->update([]);
        }

        if (version_compare($installed, '0.2.0', '<')) {
            // Le cache n'était tenu que par source (« facebook »), il l'est désormais par source et par Page.
            FeedCache::forget('facebook');
            FeedCache::forget('instagram');
        }

        update_option(self::DB_VERSION_OPTION, REZOS_VERSION, true);
    }

    public static function uninstall(): void
    {
        delete_option(Settings::OPTION);
        delete_option(self::DB_VERSION_OPTION);
        FeedCache::flushAll();
        Updater::flush();
    }
}
