<?php

declare(strict_types=1);

namespace Rezos\Twig;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class TwigFactory
{
    /**
     * Les templates du thème (dossier « rezos/ » du thème enfant puis parent)
     * priment sur ceux du plugin : copiez templates/feed.html.twig dans
     * wp-content/themes/votre-theme/rezos/ pour le personnaliser.
     */
    public static function create(string $pluginTemplatesDir): Environment
    {
        $paths = [];
        foreach (array_unique([get_stylesheet_directory(), get_template_directory()]) as $themeDir) {
            if (is_dir($themeDir . '/rezos')) {
                $paths[] = $themeDir . '/rezos';
            }
        }
        $paths[] = $pluginTemplatesDir;

        $loader = new FilesystemLoader(apply_filters('rezos/twig_paths', $paths));

        $twig = new Environment($loader, [
            'cache'            => self::cacheDir(),
            'auto_reload'      => true,
            'autoescape'       => 'html',
            'strict_variables' => defined('WP_DEBUG') && WP_DEBUG,
        ]);
        $twig->addExtension(new WordPressExtension());

        return $twig;
    }

    private static function cacheDir(): string|false
    {
        $dir = WP_CONTENT_DIR . '/cache/rezos-twig';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        return is_writable($dir) ? $dir : false;
    }
}
