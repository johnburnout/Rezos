<?php

declare(strict_types=1);

namespace Rezos\Front;

use Rezos\Plugin;

/**
 * Widget classique (Apparence > Widgets).
 */
final class Widget extends \WP_Widget
{
    private const DEFAULTS = [
        'title'     => '',
        'source'    => 'all',
        'page'      => 'all',
        'limit'     => 6,
        'columns'   => 3,
        'layout'    => 'grid',
        'show_text' => false,
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
        $instance = array_merge(self::DEFAULTS, $instance);
        $title = apply_filters('widget_title', $instance['title'], $instance, $this->id_base);

        echo $args['before_widget'];
        if ($title !== '') {
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }
        echo (new FeedRenderer(Plugin::instance()))->render([
            'source'    => $instance['source'],
            'page'      => $instance['page'],
            'limit'     => $instance['limit'],
            'columns'   => $instance['columns'],
            'layout'    => $instance['layout'],
            'show_text' => $instance['show_text'],
        ]);
        echo $args['after_widget'];
    }

    /** @param array<string, mixed> $instance */
    public function form($instance): string
    {
        $instance = array_merge(self::DEFAULTS, $instance);
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
            'source'    => in_array($new['source'] ?? '', ['all', 'facebook', 'instagram'], true) ? $new['source'] : 'all',
            'page'      => Plugin::instance()->settings()->account((string) ($new['page'] ?? '')) !== null ? (string) $new['page'] : 'all',
            'limit'     => max(1, min(50, (int) ($new['limit'] ?? 6))),
            'columns'   => max(1, min(6, (int) ($new['columns'] ?? 3))),
            'layout'    => ($new['layout'] ?? '') === 'list' ? 'list' : 'grid',
            'show_text' => !empty($new['show_text']),
        ];
    }
}
