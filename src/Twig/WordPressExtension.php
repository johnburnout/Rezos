<?php

declare(strict_types=1);

namespace Rezos\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Pont entre Twig et les fonctions WordPress utiles aux templates.
 */
final class WordPressExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            // {{ __('Voir sur Instagram') }}
            new TwigFunction('__', static fn (string $text): string => __($text, 'rezos')),
            new TwigFunction('_n', static fn (string $single, string $plural, int $n): string => _n($single, $plural, $n, 'rezos')),
            new TwigFunction('wp_nonce_field', static fn (string $action): string => wp_nonce_field($action, '_wpnonce', true, false), ['is_safe' => ['html']]),
            new TwigFunction('admin_url', static fn (string $path = ''): string => admin_url($path)),
        ];
    }

    public function getFilters(): array
    {
        return [
            // {{ post.createdAt|wp_date }} : date localisée selon les réglages du site
            new TwigFilter('wp_date', static function (\DateTimeInterface $date, ?string $format = null): string {
                return (string) wp_date($format ?? (string) get_option('date_format'), $date->getTimestamp());
            }),
            // {{ post.createdAt|time_ago }} : « il y a 3 heures »
            new TwigFilter('time_ago', static function (\DateTimeInterface $date): string {
                return sprintf(__('il y a %s', 'rezos'), human_time_diff($date->getTimestamp(), time()));
            }),
            // {{ 1234|number }} : « 1 234 » selon la langue du site
            new TwigFilter('number', static fn (int|float $n): string => number_format_i18n($n)),
            // URL nettoyée et échappée par WordPress
            new TwigFilter('esc_url', static fn (?string $url): string => esc_url((string) $url), ['is_safe' => ['html', 'html_attr']]),
            // Rend les URLs et sauts de ligne d'un texte (déjà échappé)
            new TwigFilter('rich_text', static function (string $text): string {
                return nl2br(make_clickable(esc_html($text)));
            }, ['is_safe' => ['html']]),
        ];
    }
}
