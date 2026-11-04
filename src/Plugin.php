<?php

declare(strict_types=1);

namespace Rezos;

use Rezos\Admin\SettingsPage;
use Rezos\Api\FacebookProvider;
use Rezos\Api\GraphClient;
use Rezos\Api\InstagramProvider;
use Rezos\Cache\FeedCache;
use Rezos\Feed\FeedService;
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
    private static ?self $instance = null;

    private ?Settings $settings = null;
    private ?GraphClient $graph = null;
    private ?FeedService $feed = null;
    private ?Environment $twig = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        load_plugin_textdomain('rezos', false, dirname(plugin_basename(REZOS_FILE)) . '/languages');

        (new Shortcode($this))->register();

        add_action('widgets_init', static function (): void {
            register_widget(Widget::class);
        });

        add_action('wp_enqueue_scripts', static function (): void {
            wp_register_style('rezos', REZOS_URL . 'assets/rezos.css', [], REZOS_VERSION);
        });

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
        );
    }

    public function feed(): FeedService
    {
        if ($this->feed === null) {
            $settings = $this->settings();
            $this->feed = new FeedService(
                [
                    'facebook'  => new FacebookProvider($this->graph(), $settings),
                    'instagram' => new InstagramProvider($this->graph(), $settings),
                ],
                new FeedCache((int) $settings->get('cache_ttl')),
            );
        }

        return $this->feed;
    }

    public function twig(): Environment
    {
        return $this->twig ??= TwigFactory::create(REZOS_DIR . 'templates');
    }

    public static function uninstall(): void
    {
        delete_option(Settings::OPTION);
        FeedCache::flushAll();
    }
}
