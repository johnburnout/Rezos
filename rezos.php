<?php
/**
 * Plugin Name:       Rezos
 * Description:       Affiche les publications Facebook et Instagram d'une Page sur votre site (shortcode [rezos] et widget).
 * Version:           1.3.1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Jean
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       rezos
 * Update URI:        https://github.com/johnburnout/Rezos
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('REZOS_VERSION', '1.3.1');
define('REZOS_FILE', __FILE__);
define('REZOS_DIR', plugin_dir_path(__FILE__));
define('REZOS_URL', plugin_dir_url(__FILE__));

if (!file_exists(REZOS_DIR . 'vendor/autoload.php')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Rezos : dépendances manquantes. Lancez « composer install » dans le dossier du plugin.', 'rezos')
            . '</p></div>';
    });
    return;
}

require_once REZOS_DIR . 'vendor/autoload.php';

register_uninstall_hook(__FILE__, [\Rezos\Plugin::class, 'uninstall']);

add_action('plugins_loaded', static function (): void {
    \Rezos\Plugin::instance()->boot();
});
