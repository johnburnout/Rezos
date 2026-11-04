<?php

declare(strict_types=1);

namespace Rezos\Front;

use Rezos\Plugin;

/**
 * [rezos source="instagram" limit="6" layout="grid" columns="3"]
 */
final class Shortcode
{
    public const TAG = 'rezos';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'handle']);
    }

    /** @param array<string, string>|string $atts */
    public function handle(array|string $atts = []): string
    {
        // Ignore les attributs positionnels ([rezos foo]) qui arrivent avec des clés numériques.
        $atts = is_array($atts) ? array_filter($atts, 'is_string', ARRAY_FILTER_USE_KEY) : [];

        return (new FeedRenderer($this->plugin))->render($atts);
    }
}
