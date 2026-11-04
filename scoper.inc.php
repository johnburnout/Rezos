<?php

declare(strict_types=1);

/**
 * Configuration PHP-Scoper : préfixe Symfony, Twig et PSR sous « RezosVendor\ »
 * pour éviter les conflits avec d'autres plugins qui embarquent ces bibliothèques.
 *
 * Utilisé par bin/build.sh — ne lancez pas php-scoper à la main.
 */

use Isolated\Symfony\Component\Finder\Finder;

const REZOS_PREFIX = 'RezosVendor';

return [
    'prefix' => REZOS_PREFIX,

    'finders' => [
        // Code du plugin et templates.
        Finder::create()->files()->in('src')->name('*.php'),
        Finder::create()->files()->in('templates'),
        Finder::create()->files()->in('assets'),
        // Dépendances de production (sans tests ni docs).
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->notName('/LICENSE|.*\\.md|.*\\.dist|Makefile|composer\\.json|composer\\.lock/')
            ->exclude(['doc', 'docs', 'test', 'tests', 'Tests'])
            ->in('vendor'),
        Finder::create()->append(['rezos.php', 'composer.json', 'README.md']),
    ],

    // Notre propre code garde son espace de noms. Les polyfills restent globaux :
    // ils déclarent des fonctions natives de PHP (json_validate…) protégées par
    // function_exists(), donc sans risque de conflit.
    'exclude-namespaces' => [
        'Rezos',
        'Symfony\\Polyfill',
    ],

    // Symboles WordPress : ils sont globaux et ne doivent jamais être préfixés.
    'exclude-classes' => [
        '/^WP_/',
        'wpdb',
    ],
    // Les appels de fonctions WordPress (add_action, esc_html…) sans « \ » sont laissés
    // tels quels. Il faut en revanche exclure celles citées dans une chaîne, sinon
    // function_exists('wp_cache_flush_group') devient function_exists('RezosVendor\…').
    'exclude-functions' => [
        '/^wp_/',
    ],
    'exclude-constants' => [
        '/^(ABSPATH|WP_[A-Z_]+|[A-Z_]+_IN_SECONDS|REZOS_[A-Z_]+)$/',
    ],

    'expose-global-functions' => false,

    // Fichiers laissés intacts (espace de noms global) :
    // - rezos.php : amorçage WordPress, n'utilise aucune dépendance ;
    // - polyfills et trigger_deprecation() : fonctions globales appelées comme telles
    //   par Symfony/Twig, déclarées avec « if (!function_exists()) ».
    'exclude-files' => [
        'rezos.php',
        'vendor/symfony/deprecation-contracts/function.php',
        ...array_map(
            static fn (SplFileInfo $file): string => $file->getPathname(),
            iterator_to_array(
                Finder::create()->files()->in('vendor/symfony')->path('/^polyfill-[^\/]+\/(bootstrap\d*\.php|Resources\/stubs\/)/'),
                false,
            ),
        ),
    ],

    'patchers' => [
        /*
         * Twig génère du code PHP pour les templates compilés en écrivant des noms
         * de classes dans des chaînes (« use Twig\Environment; »,
         * « \Twig\Extension\CoreExtension::captureOutput( »…). PHP-Scoper ne les voit
         * pas : on préfixe toute référence « Twig\X » qui ne l'est pas déjà.
         */
        static function (string $filePath, string $prefix, string $contents): string {
            if (!str_contains($filePath, 'vendor/twig/twig/src/')) {
                return $contents;
            }

            return (string) preg_replace(
                '/(?<![\w\\\\])(\\\\{1,2})?Twig(\\\\{1,2})(?=[A-Z])/',
                '${1}' . $prefix . '${2}Twig${2}',
                $contents,
            );
        },
    ],
];
