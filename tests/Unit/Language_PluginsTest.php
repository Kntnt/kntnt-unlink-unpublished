<?php
/**
 * Unit tests for Language_Plugins, the built-in Bogo and Polylang adapters.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Kntnt\Unlink_Unpublished\Language_Plugins;

beforeEach(function (): void {
    Functions\when('wp_parse_url')->alias('parse_url');
});

it('announces changed permalinks when Bogo changes a post language or translation', function (string $key): void {
    Actions\expectDone('kntnt_unlink_unpublished_permalinks_changed')->once();

    (new Language_Plugins())->meta_changed(1, 42, $key);
})->with(['_locale', '_original_post']);

it('ignores other post meta', function (): void {
    Actions\expectDone('kntnt_unlink_unpublished_permalinks_changed')->never();

    (new Language_Plugins())->meta_changed(1, 42, '_edit_lock');
});

it('announces changed permalinks when Polylang changes a post language', function (): void {
    Actions\expectDone('kntnt_unlink_unpublished_permalinks_changed')->once();

    (new Language_Plugins())->terms_changed(42, ['en'], [7], 'language');
});

it('ignores other taxonomies', function (): void {
    Actions\expectDone('kntnt_unlink_unpublished_permalinks_changed')->never();

    (new Language_Plugins())->terms_changed(42, ['news'], [3], 'category');
});

it('adds the hosts of Polylang languages on their own domains', function (): void {
    Functions\when('did_action')->justReturn(1);
    Functions\when('pll_languages_list')->justReturn(['sv', 'en', 'de']);
    Functions\when('pll_home_url')->alias(fn (string $slug): string => match ($slug) {
        'sv' => 'https://example.se/',
        'en' => 'https://en.example.se/',
        'de' => 'https://example.de:8443/',
    });

    $hosts = (new Language_Plugins())->polylang_hosts(['example.se', 'www.example.se']);

    expect($hosts)->toBe(['example.se', 'www.example.se', 'en.example.se', 'example.de:8443']);
});

it('leaves the hosts alone without Polylang or before it is ready', function (): void {
    Functions\when('did_action')->justReturn(0);

    expect((new Language_Plugins())->polylang_hosts(['example.se']))->toBe(['example.se']);
});
