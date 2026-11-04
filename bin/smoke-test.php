<?php

/**
 * Test de fumée du build préfixé : charge build/rezos avec un WordPress simulé,
 * à côté d'une copie NON préfixée de Twig (comme le ferait un autre plugin), puis :
 *  - vérifie qu'aucune classe de dépendance ne reste dans l'espace de noms global ;
 *  - affiche le flux via le vrai chemin shortcode → OptionsResolver → FeedService → cache → Twig ;
 *  - appelle GraphClient avec un faux client HTTP (Symfony HttpClient préfixé).
 *
 * Usage : php bin/smoke-test.php build/rezos
 */

declare(strict_types=1);

$build = realpath($argv[1] ?? __DIR__ . '/../build/rezos') ?: exit("Build introuvable.\n");
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/rezos-smoke-' . getmypid();
@mkdir($tmp, 0777, true);

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
    $failures += $ok ? 0 : 1;
}

// ---------------------------------------------------------------------------
// 1. Classmap : seules nos classes, celles de Composer et les polyfills sont globales.
// ---------------------------------------------------------------------------
echo "Classmap\n";
$classmap = require $build . '/vendor/composer/autoload_classmap.php';
$leaks = [];
foreach ($classmap as $class => $file) {
    $allowed = str_starts_with($class, 'RezosVendor\\')
        || str_starts_with($class, 'Rezos\\')
        || str_starts_with($class, 'Composer\\')
        || str_contains($file, '/symfony/polyfill-'); // polyfills : globaux par conception
    if (!$allowed) {
        $leaks[] = $class;
    }
}
check($leaks === [], 'aucune classe de dépendance non préfixée' . ($leaks ? ' : ' . implode(', ', array_slice($leaks, 0, 5)) : ''));
check(isset($classmap['RezosVendor\\Twig\\Environment']), 'Twig préfixé présent');
check(isset($classmap['Rezos\\Plugin']), 'classes du plugin présentes (non préfixées)');

// ---------------------------------------------------------------------------
// 2. Un « autre plugin » charge Twig sans préfixe, avant nous.
// ---------------------------------------------------------------------------
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Twig\\')) {
        $file = $root . '/vendor/twig/twig/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        is_file($file) && require $file;
    }
});
$otherTwig = new Twig\Environment(new Twig\Loader\ArrayLoader(['t' => 'autre plugin : {{ x|upper }}']));
$otherOutput = $otherTwig->render('t', ['x' => 'ok']);

// ---------------------------------------------------------------------------
// 3. WordPress simulé (juste ce qu'utilise le plugin).
// ---------------------------------------------------------------------------
define('ABSPATH', $tmp . '/');
define('WP_CONTENT_DIR', $tmp);
define('WP_DEBUG', true);
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['wp_options'] = [
    'rezos_settings' => ['page_id' => '123', 'page_token' => 'tok', 'ig_user_id' => '456', 'app_secret' => 's'],
];
$GLOBALS['wp_transients'] = [];
$GLOBALS['wp_actions'] = [];
$GLOBALS['wp_shortcodes'] = [];

function add_action(string $hook, callable $cb): void { $GLOBALS['wp_actions'][$hook][] = $cb; }
function do_action(string $hook): void { foreach ($GLOBALS['wp_actions'][$hook] ?? [] as $cb) { $cb(); } }
function add_filter(string $hook, callable $cb): void {}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
function add_shortcode(string $tag, callable $cb): void { $GLOBALS['wp_shortcodes'][$tag] = $cb; }
function register_uninstall_hook(string $file, callable $cb): void {}
function register_widget(string $class): void {}
function load_plugin_textdomain(...$args): bool { return true; }
function plugin_basename(string $file): string { return 'rezos/' . basename($file); }
function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
function plugin_dir_url(string $file): string { return 'https://example.test/wp-content/plugins/rezos/'; }
function is_admin(): bool { return false; }
function current_user_can(string $cap): bool { return true; }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['wp_options'][$name] ?? $default; }
function update_option(string $name, mixed $value, mixed $autoload = null): bool { $GLOBALS['wp_options'][$name] = $value; return true; }
function get_transient(string $name): mixed { return $GLOBALS['wp_transients'][$name] ?? false; }
function set_transient(string $name, mixed $value, int $ttl = 0): bool { $GLOBALS['wp_transients'][$name] = $value; return true; }
function get_stylesheet_directory(): string { return $GLOBALS['tmp_theme'] ?? '/nonexistent'; }
function get_template_directory(): string { return '/nonexistent'; }
function wp_mkdir_p(string $dir): bool { return is_dir($dir) || mkdir($dir, 0777, true); }
function wp_register_style(...$args): void {}
function wp_enqueue_style(string $handle): void {}
function wp_unique_id(string $prefix = ''): string { static $i = 0; return $prefix . ++$i; }
function __(string $text, string $domain = 'default'): string { return $text; }
function _n(string $s, string $p, int $n, string $domain = 'default'): string { return $n === 1 ? $s : $p; }
function esc_html(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function esc_url(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function make_clickable(string $s): string { return $s; }
function wp_date(string $format, int $ts): string { return date($format, $ts); }
function human_time_diff(int $from, int $to): string { return '1 jour'; }
function admin_url(string $path = ''): string { return '/wp-admin/' . $path; }
function wp_nonce_field(...$args): string { return ''; }

// ---------------------------------------------------------------------------
// 4. Chargement du plugin préfixé, comme WordPress le ferait.
// ---------------------------------------------------------------------------
echo "Plugin\n";
require $build . '/rezos.php';
do_action('plugins_loaded');
check(isset($GLOBALS['wp_shortcodes']['rezos']), 'plugin amorcé, shortcode [rezos] enregistré');

// Flux en cache : FeedService ne fera aucun appel réseau.
$post = static fn (string $source, string $id, string $date, string $type): array => [
    'source' => $source, 'id' => $id, 'text' => "Publication $id <b>gras</b>",
    'imageUrl' => "https://cdn.test/$id.jpg", 'mediaType' => $type,
    'permalink' => "https://$source.test/$id", 'createdAt' => $date, 'likes' => 3, 'comments' => 1,
];
$GLOBALS['wp_transients']['rezos_feed_facebook'] = [$post('facebook', 'fb1', '2026-11-01T10:00:00+00:00', 'image')];
$GLOBALS['wp_transients']['rezos_feed_instagram'] = [$post('instagram', 'ig1', '2026-11-02T10:00:00+00:00', 'video')];

$html = ($GLOBALS['wp_shortcodes']['rezos'])(['limit' => '5', 'show_stats' => 'true']);
check(str_contains($html, 'rezos-post--instagram') && str_contains($html, 'rezos-post--facebook'), 'shortcode : les deux sources sont affichées');
check(strpos($html, 'ig1') < strpos($html, 'fb1'), 'shortcode : tri par date décroissante');
check(str_contains($html, '&lt;b&gt;gras&lt;/b&gt;'), 'shortcode : texte échappé par Twig');
check(str_contains($html, '♥ 3'), 'shortcode : statistiques (show_stats)');

$error = ($GLOBALS['wp_shortcodes']['rezos'])(['source' => 'tiktok']);
check(str_contains($error, 'Source inconnue'), 'OptionsResolver préfixé : option invalide refusée');

// Compilation Twig avec cache disque (le code généré doit utiliser les classes préfixées).
$compiled = glob(WP_CONTENT_DIR . '/cache/rezos-twig/*/*.php') ?: [];
$compiledCode = implode('', array_map('file_get_contents', $compiled));
check($compiled !== [] && str_contains($compiledCode, 'use RezosVendor\\Twig\\Environment;'), 'templates compilés : classes Twig préfixées');
check(!preg_match('/(?<![\\\\\w])Twig\\\\/', str_replace('RezosVendor\\Twig\\', '', $compiledCode)), 'templates compilés : aucune référence à Twig non préfixé');

// ---------------------------------------------------------------------------
// 5. GraphClient avec Symfony HttpClient préfixé (faux transport).
// ---------------------------------------------------------------------------
echo "API Graph\n";
$mock = new RezosVendor\Symfony\Component\HttpClient\MockHttpClient([
    new RezosVendor\Symfony\Component\HttpClient\Response\MockResponse('{"data":[{"id":"1"}]}'),
    new RezosVendor\Symfony\Component\HttpClient\Response\MockResponse('{"error":{"type":"OAuthException","code":190,"message":"expiré"}}', ['http_code' => 400]),
]);
$graph = new Rezos\Api\GraphClient($mock, 'v25.0');
check(($graph->get('me', [], 'tok', 's')['data'][0]['id'] ?? null) === '1', 'requête GET décodée');
try {
    $graph->get('me', [], 'tok');
    check(false, 'erreur Graph convertie en GraphException');
} catch (Rezos\Api\GraphException $e) {
    check($e->isAuthError(), 'erreur Graph convertie en GraphException (code 190)');
}

// ---------------------------------------------------------------------------
// 6. Cohabitation avec l'autre Twig.
// ---------------------------------------------------------------------------
echo "Cohabitation\n";
check($otherOutput === 'autre plugin : OK', 'Twig non préfixé de l\'« autre plugin » fonctionne');
check(get_class(Rezos\Plugin::instance()->twig()) === 'RezosVendor\\Twig\\Environment', 'Rezos utilise sa propre copie de Twig');
check($otherTwig->render('t', ['x' => 'encore']) === 'autre plugin : ENCORE', 'l\'autre Twig fonctionne toujours après Rezos');

exec('rm -rf ' . escapeshellarg($tmp));

echo $failures === 0 ? "\nOK\n" : "\n$failures échec(s)\n";
exit($failures === 0 ? 0 : 1);
