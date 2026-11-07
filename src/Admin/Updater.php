<?php

declare(strict_types=1);

namespace Rezos\Admin;

/**
 * Mises à jour depuis les releases GitHub, dans Tableau de bord › Mises à jour et Extensions.
 *
 * S'appuie sur l'en-tête « Update URI » du plugin (WordPress 5.8+) : WordPress n'interroge pas
 * wordpress.org pour Rezos et demande la version disponible au filtre update_plugins_github.com.
 * Seules les releases publiées qui contiennent le zip construit (rezos-x.y.z.zip, dépendances
 * incluses) sont proposées.
 */
final class Updater
{
    public const REPO = 'johnburnout/Rezos';
    public const SLUG = 'rezos';

    /** Dernière branche de WordPress sur laquelle Rezos a été vérifié (« Testé jusqu'à »). */
    public const TESTED_UP_TO = '7.1';

    private const CACHE_KEY = 'rezos_latest_release';
    private const CACHE_TTL = 6 * 3600;
    private const ERROR_TTL = 3600;

    public function register(): void
    {
        add_filter('update_plugins_github.com', [$this, 'checkUpdate'], 10, 3);
        add_filter('plugins_api', [$this, 'pluginInfo'], 20, 3);

        // « Vérifier à nouveau » dans Tableau de bord › Mises à jour : on ignore notre cache.
        // Priorité 9 : avant wp_update_plugins(), accroché au même hook en priorité 10.
        add_action('load-update-core.php', static function (): void {
            if (!empty($_GET['force-check'])) {
                self::flush();
            }
        }, 9);
    }

    /**
     * @param array<string, mixed>|false $update
     * @param array<string, mixed>       $pluginData
     * @return array<string, mixed>|false
     */
    public function checkUpdate(mixed $update, array $pluginData, string $pluginFile): mixed
    {
        if ($pluginFile !== plugin_basename(REZOS_FILE)) {
            return $update;
        }

        $release = $this->latestRelease();
        if ($release === null) {
            return $update;
        }

        // WordPress propose la mise à jour si « version » est plus récente que la version installée.
        return [
            'id'           => 'github.com/' . self::REPO,
            'slug'         => self::SLUG,
            'plugin'       => $pluginFile,
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires'     => '6.0',
            'requires_php' => '8.1',
            'tested'       => self::tested(),
        ];
    }

    /** Fenêtre « Voir les détails de la version x.y.z ». */
    public function pluginInfo(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }

        $release = $this->latestRelease();
        if ($release === null) {
            return $result;
        }

        return (object) [
            'name'          => 'Rezos',
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'author'        => 'Jean',
            'homepage'      => 'https://github.com/' . self::REPO,
            'download_link' => $release['package'],
            'requires'      => '6.0',
            'requires_php'  => '8.1',
            'tested'        => self::tested(),
            'last_updated'  => $release['date'],
            'sections'      => [
                'changelog' => self::markdownToHtml($release['notes']),
            ],
        ];
    }

    /**
     * Version « testée » annoncée à WordPress, sinon il affiche « Compatibilité : non testée ».
     * WordPress compare la version complète (7.1 < 7.1.4) : comme wordpress.org, une branche
     * testée couvre ses versions de maintenance, on renvoie donc la version du site si elle en fait partie.
     */
    public static function tested(): string
    {
        $current = (string) ($GLOBALS['wp_version'] ?? '');
        $branch = implode('.', array_slice(explode('.', $current), 0, 2));

        return $branch === self::TESTED_UP_TO ? $current : self::TESTED_UP_TO;
    }

    public static function flush(): void
    {
        delete_site_transient(self::CACHE_KEY);
    }

    /**
     * Dernière release publiée, mise en cache (6 h, 1 h en cas d'échec pour ne pas insister).
     *
     * @return array{version: string, package: string, url: string, notes: string, date: string}|null
     */
    private function latestRelease(): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return isset($cached['version']) ? $cached : null;
        }

        $release = $this->fetchLatestRelease();
        set_site_transient(self::CACHE_KEY, $release ?? ['error' => true], $release ? self::CACHE_TTL : self::ERROR_TTL);

        return $release;
    }

    /** @return array{version: string, package: string, url: string, notes: string, date: string}|null */
    private function fetchLatestRelease(): ?array
    {
        $response = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/releases/latest', [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Rezos/' . REZOS_VERSION,
            ],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease'])) {
            return null;
        }

        $version = ltrim((string) ($data['tag_name'] ?? ''), 'vV');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            return null;
        }

        // Le zip construit par bin/build.sh, pas l'archive des sources (sans dépendances).
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (is_array($asset) && ($asset['name'] ?? '') === self::SLUG . '-' . $version . '.zip') {
                return [
                    'version' => $version,
                    'package' => (string) $asset['browser_download_url'],
                    'url'     => (string) ($data['html_url'] ?? 'https://github.com/' . self::REPO),
                    'notes'   => (string) ($data['body'] ?? ''),
                    'date'    => (string) ($data['published_at'] ?? ''),
                ];
            }
        }

        return null;
    }

    /** Markdown minimal des notes de release (titres, listes, gras, italique, code). */
    private static function markdownToHtml(string $markdown): string
    {
        $inline = static fn (string $s): string => preg_replace(
            ['/`([^`]+)`/', '/\*\*(.+?)\*\*/', '/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/'],
            ['<code>$1</code>', '<strong>$1</strong>', '<em>$1</em>'],
            esc_html($s),
        ) ?? esc_html($s);

        $html = '';
        $inList = false;
        $inCode = false;
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m) && !$inCode) {
                $html .= ($inList ? '' : "<ul>\n") . '<li>' . $inline($m[1]) . "</li>\n";
                $inList = true;
                continue;
            }
            if ($inList && trim($line) !== '') {
                $html .= "</ul>\n";
                $inList = false;
            }
            if (str_starts_with(ltrim($line), '```')) {
                $html .= $inCode ? "</pre>\n" : '<pre>';
                $inCode = !$inCode;
                continue;
            }
            if ($inCode) {
                $html .= esc_html(ltrim($line)) . "\n";
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $html .= '<h4>' . $inline($m[2]) . "</h4>\n";
            } elseif (trim($line) !== '') {
                $html .= '<p>' . $inline(trim($line)) . "</p>\n";
            }
        }

        return $html . ($inList ? "</ul>\n" : '') . ($inCode ? "</pre>\n" : '');
    }
}
