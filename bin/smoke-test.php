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

// Réglages au format 0.1 (une seule Page) : ils doivent être migrés automatiquement.
$GLOBALS['wp_options'] = [
    'rezos_settings' => ['page_id' => '111', 'page_name' => 'Boulangerie', 'page_token' => 'tok1',
                         'ig_user_id' => '911', 'ig_username' => 'boulangerie', 'app_secret' => 's',
                         'default_limit' => 9],
    // Cache au format 0.1 (par source uniquement) : doit être supprimé à la mise à jour.
    'rezos_stale_facebook'  => [['legacy']],
    'rezos_stale_instagram' => [['legacy']],
];
$GLOBALS['wp_transients']['rezos_feed_facebook'] = [['legacy']];
$GLOBALS['wp_transients']['rezos_feed_instagram'] = [['legacy']];
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
function delete_option(string $name): bool { unset($GLOBALS['wp_options'][$name]); return true; }
function delete_transient(string $name): bool { unset($GLOBALS['wp_transients'][$name]); return true; }
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
function shortcode_parse_atts(string $text): array|string
{
    preg_match_all('/([\w-]+)\s*=\s*"([^"]*)"/', $text, $m, PREG_SET_ORDER);

    return $text === '' ? '' : array_column($m, 2, 1);
}

// ---------------------------------------------------------------------------
// 4. Chargement du plugin préfixé, comme WordPress le ferait.
// ---------------------------------------------------------------------------
echo "Plugin\n";
require $build . '/rezos.php';
do_action('plugins_loaded');
check(isset($GLOBALS['wp_shortcodes']['rezos']), 'plugin amorcé, shortcode [rezos] enregistré');

// Mise à jour 0.1 → 1.0 au démarrage.
$opts = $GLOBALS['wp_options'];
check(($opts['rezos_db_version'] ?? null) === REZOS_VERSION, 'mise à jour : version des données enregistrée (' . REZOS_VERSION . ')');
check(!isset($opts['rezos_settings']['page_id'], $opts['rezos_settings']['default_limit']) && count($opts['rezos_settings']['accounts'] ?? []) === 1, 'mise à jour : réglages réenregistrés au format « accounts »');
check(!isset($opts['rezos_stale_facebook'], $opts['rezos_stale_instagram'])
    && !isset($GLOBALS['wp_transients']['rezos_feed_facebook'], $GLOBALS['wp_transients']['rezos_feed_instagram']),
    'mise à jour : ancien cache 0.1 supprimé');

$shortcode = $GLOBALS['wp_shortcodes']['rezos'];
$settings = Rezos\Plugin::instance()->settings();

// Migration des réglages 0.1
$legacy = $settings->accounts();
check(count($legacy) === 1 && $legacy[0]->id === '111' && $legacy[0]->igUsername === 'boulangerie', 'réglages 0.1 migrés vers une liste de Pages');
check($settings->get('default_rows') === 3 && $settings->get('default_columns') === 3 && $settings->get('default_limit') === null, 'default_limit=9 migré en 3 × 3');

// Une Page seule : pas de nom de Page affiché (show_account auto).
$post = static fn (string $source, string $id, string $date, string $type, string $account): array => [
    'source' => $source, 'id' => $id, 'text' => "Publication $id <b>gras</b>",
    'imageUrl' => "https://cdn.test/$id.jpg", 'mediaType' => $type,
    'permalink' => "https://$source.test/$id", 'createdAt' => $date, 'likes' => 3, 'comments' => 1,
    'accountId' => $account, 'accountName' => "Nom-$account",
];
// Flux en cache (clé source_IDPage) : FeedService ne fera aucun appel réseau.
$GLOBALS['wp_transients']['rezos_feed_facebook_111'] = [$post('facebook', 'fb1', '2026-11-01T10:00:00+00:00', 'image', '111')];
$GLOBALS['wp_transients']['rezos_feed_instagram_111'] = [$post('instagram', 'ig1', '2026-11-02T10:00:00+00:00', 'video', '111')];
$GLOBALS['wp_transients']['rezos_feed_facebook_222'] = [$post('facebook', 'fb2', '2026-11-03T10:00:00+00:00', 'text', '222')];

$html = $shortcode(['limit' => '5', 'show_stats' => 'true']);
check(str_contains($html, 'rezos-post--instagram') && str_contains($html, 'rezos-post--facebook'), 'une Page : les deux sources sont affichées');
check(strpos($html, 'ig1') < strpos($html, 'fb1'), 'une Page : tri par date décroissante');
check(str_contains($html, '&lt;b&gt;gras&lt;/b&gt;'), 'texte échappé par Twig');
check(str_contains($html, '♥ 3'), 'statistiques (show_stats)');
check(!str_contains($html, 'rezos-post__account'), 'une Page : nom de Page masqué par défaut');

// Grille : colonnes × lignes, première ligne en pleine largeur.
$count = static fn (string $html): int => substr_count($html, 'class="rezos__item"');
$GLOBALS['wp_transients']['rezos_feed_facebook_111'] = array_map(
    static fn (int $i): array => $post('facebook', "g$i", sprintf('2026-10-%02dT10:00:00+00:00', $i), 'image', '111'),
    range(1, 20),
);
$html = $shortcode([]);
check($count($html) === 9 && !str_contains($html, 'rezos--featured'), 'grille par défaut : 3 par ligne × 3 lignes = 9');
$html = $shortcode(['columns' => '4', 'rows' => '2']);
check($count($html) === 8 && str_contains($html, '--rezos-columns: 4'), 'columns="4" rows="2" : 8 publications');
$html = $shortcode(['columns' => '3', 'rows' => '3', 'featured' => 'true']);
check($count($html) === 7 && str_contains($html, 'rezos--featured'), 'featured : 1 + 3 + 3 = 7 publications');
$html = $shortcode(['layout' => 'list', 'rows' => '4', 'columns' => '3']);
check($count($html) === 4, 'layout="list" : une publication par ligne');
check($count($shortcode(['limit' => '5', 'rows' => '4'])) === 5, 'limit remplace columns × rows');
check(Rezos\Front\Shortcode::parse('[rezos rows="2" featured="true"]') === ['rows' => '2', 'featured' => 'true']
    && Rezos\Front\Shortcode::parse(' [rezos] ') === []
    && Rezos\Front\Shortcode::parse('[gallery ids="1"]') === null, 'aperçu : analyse du shortcode saisi');
$GLOBALS['wp_transients']['rezos_feed_facebook_111'] = [$post('facebook', 'fb1', '2026-11-01T10:00:00+00:00', 'image', '111')];

$error = $shortcode(['source' => 'tiktok']);
check(str_contains($error, 'Source inconnue'), 'OptionsResolver préfixé : option invalide refusée');

// Deux Pages, dont une sans Instagram.
$settings->saveAccounts([...$settings->accounts(), new Rezos\Model\Account('222', 'Fromagerie', 'tok2')]);

$html = $shortcode(['limit' => '10']);
check(str_contains($html, 'fb1') && str_contains($html, 'ig1') && str_contains($html, 'fb2'), 'deux Pages : publications des deux Pages');
check(strpos($html, 'fb2') < strpos($html, 'ig1'), 'deux Pages : tri par date sur l\'ensemble');
check(str_contains($html, 'rezos-post__account">Nom-222'), 'deux Pages : nom de la Page affiché');

$html = $shortcode(['page' => '222']);
check(str_contains($html, 'fb2') && !str_contains($html, 'fb1') && !str_contains($html, 'ig1'), 'page="222" : uniquement cette Page');
check(!str_contains($html, 'rezos-post__account'), 'page="222" : nom de Page masqué (une seule Page)');

$html = $shortcode(['page' => '@Boulangerie', 'source' => 'instagram']);
check(str_contains($html, 'ig1') && !str_contains($html, 'fb'), 'page="@compte" : sélection par compte Instagram');

$html = $shortcode(['page' => '111,222', 'show_account' => 'false']);
check(str_contains($html, 'fb2') && str_contains($html, 'ig1') && !str_contains($html, 'rezos-post__account'), 'page="111,222" + show_account="false"');

$error = $shortcode(['page' => '999']);
check(str_contains($error, 'Page inconnue'), 'page inconnue refusée');

// Templates d'administration (réglages avec Pages connectées + sélection en attente, widget).
$twig = Rezos\Plugin::instance()->twig();
$admin = $twig->render('admin/settings.html.twig', [
    'settings' => $settings->all(), 'accounts' => $settings->accounts(), 'connected' => true,
    'pending' => [['id' => '222', 'name' => 'Fromagerie', 'ig_username' => null], ['id' => '333', 'name' => 'Épicerie', 'ig_username' => 'epicerie']],
    'notice' => null, 'post_url' => '/wp-admin/admin-post.php', 'redirect_uri' => '/',
    'preview_code' => '[rezos rows="1"]', 'preview_html' => '<div class="rezos">APERCU</div>', 'preview_error' => null,
]);
check(str_contains($admin, 'name="default_rows"') && str_contains($admin, 'name="default_featured"'), 'admin : réglages colonnes / lignes / pleine largeur');
check(str_contains($admin, '<div class="rezos">APERCU</div>') && str_contains($admin, 'value="[rezos rows=&quot;1&quot;]"'), 'admin : aperçu du shortcode');
check(str_contains($admin, 'preview=%5Brezos%20page%3D%22222%22%5D'), 'admin : lien d\'aperçu par Page');
check(str_contains($admin, '[rezos page="222"]') && str_contains($admin, '@boulangerie'), 'admin : liste des Pages connectées');
check((bool) preg_match('/value="222"\s+checked/', $admin) && !preg_match('/value="333"\s+checked/', $admin), 'admin : Pages déjà connectées pré-cochées');
$widget = $twig->render('admin/widget-form.html.twig', [
    'instance' => ['title' => '', 'source' => 'all', 'page' => '222', 'columns' => 3, 'rows' => 2, 'featured' => true, 'layout' => 'grid', 'show_text' => false],
    'fields' => array_map(static fn (string $k): array => ['id' => $k, 'name' => $k], array_combine(
        $keys = ['title', 'source', 'page', 'columns', 'rows', 'featured', 'layout', 'show_text'], $keys,
    )),
    'accounts' => $settings->accounts(),
]);
check((bool) preg_match('/value="222" selected/', $widget), 'widget : choix de la Page');
check(str_contains($widget, 'name="rows"') && (bool) preg_match('/name="featured"[^>]*checked/', $widget), 'widget : lignes et pleine largeur');

// Compilation Twig avec cache disque (le code généré doit utiliser les classes préfixées).
$compiled = glob(WP_CONTENT_DIR . '/cache/rezos-twig/*/*.php') ?: [];
$compiledCode = implode('', array_map('file_get_contents', $compiled));
check($compiled !== [] && str_contains($compiledCode, 'use RezosVendor\\Twig\\Environment;'), 'templates compilés : classes Twig préfixées');
check(!preg_match('/(?<![\\\\\w])Twig\\\\/', str_replace('RezosVendor\\Twig\\', '', $compiledCode)), 'templates compilés : aucune référence à Twig non préfixé');

// ---------------------------------------------------------------------------
// 5. GraphClient avec Symfony HttpClient préfixé (faux transport).
// ---------------------------------------------------------------------------
echo "API Graph\n";
$requests = [];
$mock = new RezosVendor\Symfony\Component\HttpClient\MockHttpClient(static function (string $method, string $url) use (&$requests) {
    $requests[] = $url;
    $json = match (count($requests)) {
        1 => '{"data":[{"id":"1"}]}',
        2 => '{"error":{"type":"OAuthException","code":190,"message":"expiré"}}',
        3 => '{"data":[{"id":"p1","name":"A","access_token":"t1"}],"paging":{"cursors":{"after":"CUR"},"next":"https://graph.facebook.com/next"}}',
        default => '{"data":[{"id":"p2","name":"B","access_token":"t2","instagram_business_account":{"id":"ig2","username":"b"}}],"paging":{"cursors":{"after":"END"}}}',
    };

    return new RezosVendor\Symfony\Component\HttpClient\Response\MockResponse($json, ['http_code' => count($requests) === 2 ? 400 : 200]);
});
$graph = new Rezos\Api\GraphClient($mock, 'v25.0');
check(($graph->get('me', [], 'tok', 's')['data'][0]['id'] ?? null) === '1', 'requête GET décodée');
try {
    $graph->get('me', [], 'tok');
    check(false, 'erreur Graph convertie en GraphException');
} catch (Rezos\Api\GraphException $e) {
    check($e->isAuthError(), 'erreur Graph convertie en GraphException (code 190)');
}
$pages = $graph->listPages('tok', 's');
check(array_column($pages, 'id') === ['p1', 'p2'] && $pages[1]['ig_id'] === 'ig2', 'me/accounts : toutes les Pages (pagination)');
check(str_contains($requests[3] ?? '', 'after=CUR') && count($requests) === 4, 'me/accounts : curseur « after » suivi, arrêt sans « next »');

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
