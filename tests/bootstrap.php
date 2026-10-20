<?php
/**
 * Test bootstrap.
 *
 * Loads the Composer autoloader, initialises Patchwork early, registers a code
 * manipulation that strips `final` from the plugin's classes, and loads the
 * HTML API of the WordPress version at the plugin's floor (6.9, from the
 * johnpbloch/wordpress-core dev dependency). The HTML API is a self-contained
 * set of classes, so the link rewriter is unit-tested against the real parser
 * rather than a stand-in.
 *
 * @package Tests
 * @since   0.1.0
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// A minimal stand-in for WordPress's WP_Post with the properties the plugin
// reads. PHPStan gets the real class from the WordPress stubs.
if (!class_exists('WP_Post')) {
    #[\AllowDynamicProperties]
    class WP_Post
    {
        public int $ID = 0;
        public string $post_title = '';
        public string $post_content = '';
        public string $post_status = 'publish';
        public string $post_type = 'page';
        public string $post_name = '';
        public int $post_parent = 0;
        public string $post_date_gmt = '0000-00-00 00:00:00';
        public string $filter = 'raw';

        public function __construct(array $properties = [])
        {
            foreach ($properties as $name => $value) {
                $this->$name = $value;
            }
        }
    }
}

// Initialise Patchwork before any plugin class is autoloaded so every class
// passes through Patchwork's source-transformation pipeline.
require_once dirname(__DIR__) . '/vendor/antecedent/patchwork/Patchwork.php';

// Strip `final` from the plugin's classes so Mockery can mock them.
$classes_dir = realpath(dirname(__DIR__) . '/classes') . DIRECTORY_SEPARATOR;
\Patchwork\CodeManipulation\register(function (\Patchwork\CodeManipulation\Source $s) use ($classes_dir): void {
    if (!isset($s->file) || !str_starts_with($s->file, $classes_dir)) {
        return;
    }
    foreach ($s->all(T_FINAL) as $offset) {
        $next = $s->skip(\Patchwork\CodeManipulation\Source::junk(), $offset);
        if ($s->is(T_CLASS, $next) || $s->is(T_READONLY, $next)) {
            $s->splice('', $offset, 1);
        }
    }
});

// Load the WordPress HTML API in the order wp-settings.php loads it.
$wpinc = dirname(__DIR__) . '/vendor/johnpbloch/wordpress-core/wp-includes';
require_once $wpinc . '/class-wp-token-map.php';
foreach ([
    'html5-named-character-references',
    'class-wp-html-attribute-token',
    'class-wp-html-span',
    'class-wp-html-doctype-info',
    'class-wp-html-text-replacement',
    'class-wp-html-decoder',
    'class-wp-html-tag-processor',
    'class-wp-html-unsupported-exception',
    'class-wp-html-active-formatting-elements',
    'class-wp-html-open-elements',
    'class-wp-html-token',
    'class-wp-html-stack-event',
    'class-wp-html-processor-state',
    'class-wp-html-processor',
] as $file) {
    require_once $wpinc . '/html-api/' . $file . '.php';
}
