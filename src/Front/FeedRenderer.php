<?php

declare(strict_types=1);

namespace Rezos\Front;

use Rezos\Plugin;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface as OptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Rendu partagé entre le shortcode et le widget.
 */
final class FeedRenderer
{
    private ?OptionsResolver $resolver = null;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    /** @param array<string, mixed> $options */
    public function render(array $options): string
    {
        try {
            $opts = $this->resolver()->resolve($options);
        } catch (OptionsException $e) {
            return $this->errorBox($e->getMessage());
        }

        $feed = $this->plugin->feed();
        $posts = $feed->getPosts($opts['source'], $opts['limit'], $opts['type']);

        wp_enqueue_style('rezos');

        $html = $this->plugin->twig()->render($opts['template'], [
            'posts'   => $posts,
            'options' => $opts,
            'errors'  => current_user_can('manage_options') ? $feed->lastErrors() : [],
            'uid'     => wp_unique_id('rezos-'),
        ]);

        return (string) apply_filters('rezos/html', $html, $posts, $opts);
    }

    private function resolver(): OptionsResolver
    {
        if ($this->resolver !== null) {
            return $this->resolver;
        }

        $sources = $this->plugin->feed()->availableSources();
        $toBool = static fn (Options $o, mixed $v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN);

        $r = new OptionsResolver();
        $r->setDefaults([
            'source'    => 'all',
            'limit'     => (int) $this->plugin->settings()->get('default_limit'),
            'layout'    => 'grid',
            'columns'   => 3,
            'type'      => null,
            'show_text' => true,
            'show_date' => true,
            'show_stats'=> false,
            'excerpt'   => 140,
            'template'  => 'feed.html.twig',
        ]);

        // « facebook,instagram », « instagram » ou « all »
        $r->setNormalizer('source', static function (Options $o, mixed $v) use ($sources): array {
            $list = array_filter(array_map('trim', explode(',', strtolower((string) $v))));
            if ($list === [] || in_array('all', $list, true)) {
                return $sources;
            }
            $unknown = array_diff($list, $sources);
            if ($unknown !== []) {
                throw new \Symfony\Component\OptionsResolver\Exception\InvalidOptionsException(
                    sprintf('Source inconnue « %s ». Valeurs possibles : all, %s.', implode(', ', $unknown), implode(', ', $sources)),
                );
            }

            return array_values($list);
        });
        $r->setNormalizer('limit', static fn (Options $o, mixed $v): int => max(1, min(50, (int) $v)));
        $r->setNormalizer('columns', static fn (Options $o, mixed $v): int => max(1, min(6, (int) $v)));
        $r->setNormalizer('excerpt', static fn (Options $o, mixed $v): int => max(0, (int) $v));
        $r->setNormalizer('type', static fn (Options $o, mixed $v): ?string => $v === null || $v === '' ? null : strtolower((string) $v));
        $r->setNormalizer('show_text', $toBool);
        $r->setNormalizer('show_date', $toBool);
        $r->setNormalizer('show_stats', $toBool);
        $r->setAllowedValues('layout', ['grid', 'list']);
        $r->setAllowedValues('type', [null, 'image', 'video', 'album', 'text']);
        // Un nom de fichier simple : pas de remontée de répertoire.
        $r->setAllowedValues('template', static fn (mixed $v): bool => is_string($v) && (bool) preg_match('/^[a-z0-9_\-]+\.html\.twig$/i', $v));

        return $this->resolver = $r;
    }

    private function errorBox(string $message): string
    {
        if (!current_user_can('manage_options')) {
            return '';
        }

        return '<div class="rezos-error">' . esc_html('Rezos : ' . $message) . '</div>';
    }
}
