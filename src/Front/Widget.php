<?php

declare(strict_types=1);

namespace Rezos\Front;

use Rezos\Plugin;
use Rezos\Settings;

/**
 * Widget classique (Apparence > Widgets).
 */
final class Widget extends \WP_Widget
{
    private const DEFAULTS = [
        'title'     => '',
        'source'    => 'all',
        'page'      => 'all',
        'columns'   => 3,
        'rows'      => 2,
        'featured'  => false,
        'layout'    => 'grid',
        'show_text' => false,
        'follow'    => true,
    ];

    public function __construct()
    {
        parent::__construct('rezos_widget', 'Rezos', [
            'description' => __('Dernières publications Facebook / Instagram.', 'rezos'),
        ]);
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $instance
     */
    public function widget($args, $instance): void
    {
        $instance = self::withDefaults($instance);
        $title = apply_filters('widget_title', $instance['title'], $instance, $this->id_base);

        echo $args['before_widget'];
        if ($title !== '') {
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }
        echo Plugin::instance()->renderer()->render([
            'source'    => $instance['source'],
            'page'      => $instance['page'],
            'columns'   => $instance['columns'],
            'rows'      => $instance['rows'],
            'featured'  => $instance['featured'],
            'layout'    => $instance['layout'],
            'show_text' => $instance['show_text'],
            'follow'    => $instance['follow'],
        ]);
        echo $args['after_widget'];
    }

    /** @param array<string, mixed> $instance */
    public function form($instance): string
    {
        $instance = self::withDefaults($instance);
        $fields = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $fields[$key] = ['id' => $this->get_field_id($key), 'name' => $this->get_field_name($key)];
        }

        echo Plugin::instance()->twig()->render('admin/widget-form.html.twig', [
            'instance' => $instance,
            'fields'   => $fields,
            'accounts' => Plugin::instance()->settings()->accounts(),
        ]);

        return '';
    }

    /**
     * @param array<string, mixed> $new
     * @param array<string, mixed> $old
     * @return array<string, mixed>
     */
    public function update($new, $old): array
    {
        return [
            'title'     => sanitize_text_field($new['title'] ?? ''),
            'source'    => in_array($new['source'] ?? '', Plugin::instance()->feed()->availableSources(), true) ? $new['source'] : 'all',
            'page'      => Plugin::instance()->settings()->account((string) ($new['page'] ?? '')) !== null ? (string) $new['page'] : 'all',
            'columns'   => max(1, min(Settings::MAX_COLUMNS, (int) ($new['columns'] ?? 3))),
            'rows'      => max(1, min(Settings::MAX_ROWS, (int) ($new['rows'] ?? 2))),
            'featured'  => !empty($new['featured']),
            'layout'    => ($new['layout'] ?? '') === 'list' ? 'list' : 'grid',
            'show_text' => !empty($new['show_text']),
            'follow'    => !empty($new['follow']),
        ];
    }

    /**
     * Widgets enregistrés avec l'ancien réglage « limit » (nombre total) → nombre de lignes.
     *
     * @param array<string, mixed> $instance
     * @return array<string, mixed>
     */
    private static function withDefaults(array $instance): array
    {
        if (!isset($instance['rows']) && isset($instance['limit'])) {
            $columns = max(1, (int) ($instance['columns'] ?? self::DEFAULTS['columns']));
            $instance['rows'] = max(1, min(Settings::MAX_ROWS, (int) ceil((int) $instance['limit'] / $columns)));
        }
        unset($instance['limit']);

        return array_merge(self::DEFAULTS, $instance);
    }
}
