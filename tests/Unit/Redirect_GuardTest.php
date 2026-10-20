<?php
/**
 * Unit tests for Redirect_Guard: which redirects it vetoes (spec §6).
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Unlink_Unpublished\Href_Normaliser;
use Kntnt\Unlink_Unpublished\Index;
use Kntnt\Unlink_Unpublished\Index_Store;
use Kntnt\Unlink_Unpublished\Liveness;
use Kntnt\Unlink_Unpublished\Not_Found_Page;
use Kntnt\Unlink_Unpublished\Redirect_Guard;

/** Stands in for the exit that ends a rendered 404. */
if (!class_exists('Rendered_404')) {
    final class Rendered_404 extends RuntimeException {}
}

beforeEach(function (): void {
    Functions\when('wp_parse_url')->alias('parse_url');
    Functions\when('get_current_user_id')->justReturn(0);
    Functions\when('get_post')->justReturn(null);
    Functions\when('current_user_can')->justReturn(false);
    Functions\when('is_admin')->justReturn(false);
    Functions\when('did_action')->alias(fn (string $hook): int => $hook === 'wp' ? 1 : 0);
    Functions\when('is_404')->justReturn(true);
});

/**
 * A guard over an index with an unpublished, a private and a deleted target,
 * whose 404 page throws instead of exiting.
 */
function redirect_guard(?Not_Found_Page $page = null, bool $logs_vetoes = false): Redirect_Guard
{
    $store = Mockery::mock(Index_Store::class);
    $store->allows('index')->andReturn(Index::from_array([
        'paths' => ['/dead' => [1, 'unpublished', true], '/private' => [2, 'private', false], '/gone' => [3, 'deleted', true]],
        'ids' => [1 => ['unpublished', true], 2 => ['private', false], 3 => ['deleted', true]],
    ]));

    return new Redirect_Guard($store, new Liveness(), new Href_Normaliser('https://www.example.com'), $page ?? rendering_page(), $logs_vetoes);
}

/** A 404 page that is expected to render once. */
function rendering_page(): Not_Found_Page
{
    $page = Mockery::mock(Not_Found_Page::class);
    $page->expects('render')->andThrow(new Rendered_404());
    return $page;
}

/** A 404 page that fails the test if it renders. */
function silent_page(): Not_Found_Page
{
    $page = Mockery::mock(Not_Found_Page::class);
    $page->allows('render')->andThrow(new LogicException('The 404 page rendered.'));
    return $page;
}

it('vetoes a redirect on a 404 to a target that is not live and renders the 404', function (string $location): void {
    expect(fn () => redirect_guard()->veto_redirect($location, 301))->toThrow(Rendered_404::class);
})->with([
    'page_id' => ['https://www.example.com/?page_id=1'],
    'p' => ['https://www.example.com/?p=1'],
    'post type and p' => ['/?post_type=event&p=1'],
    'absolute path' => ['https://www.example.com/dead/'],
    'root-relative path' => ['/dead/'],
    'bare host' => ['http://example.com/dead'],
    'private target, anonymous visitor' => ['/private/'],
    'tombstone' => ['/gone/'],
    'tombstone ID form' => ['/?page_id=3'],
]);

it('lets a redirect on a 404 through when the index does not hold its location or the location is on another site', function (string $location): void {
    expect(redirect_guard(silent_page())->veto_redirect($location, 301))->toBe($location);
})->with([
    'published page' => ['https://www.example.com/live/'],
    'home page' => ['https://www.example.com/'],
    'ID form of a published page' => ['/?page_id=4'],
    'another site' => ['https://other.example/dead/'],
]);

it('lets a redirect on a 404 through to a private target the visitor can read', function (): void {
    Functions\when('current_user_can')->justReturn(true);

    expect(redirect_guard(silent_page())->veto_redirect('/private/', 302))->toBe('/private/');
});

it('judges only redirects on a front-end 404', function (string $function, mixed $value): void {
    Functions\when($function)->justReturn($value);

    expect(redirect_guard(silent_page())->veto_redirect('/dead/', 301))->toBe('/dead/');
})->with([
    'in the admin' => ['is_admin', true],
    'before the main query' => ['did_action', 0],
    'on a request that is not a 404' => ['is_404', false],
]);

it('writes one line to the debug log for a veto when debug logging is on, and nothing to the database', function (): void {
    $log = tempnam(sys_get_temp_dir(), 'kntnt-log-');
    $previous = ini_set('error_log', $log);
    $_SERVER['REQUEST_URI'] = '/?page_id=1';
    Functions\when('wp_unslash')->returnArg();
    Functions\when('sanitize_url')->returnArg();
    foreach (['add_option', 'update_option', 'set_transient', 'add_post_meta', 'update_post_meta'] as $writer) {
        Functions\expect($writer)->never();
    }

    try {
        redirect_guard(logs_vetoes: true)->veto_redirect('https://www.example.com/?page_id=1', 301);
    } catch (Rendered_404) {
    } finally {
        ini_set('error_log', (string) $previous);
        unset($_SERVER['REQUEST_URI']);
    }
    $lines = file($log, FILE_IGNORE_NEW_LINES);
    unlink($log);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toContain('301')
        ->and($lines[0])->toContain('/?page_id=1')
        ->and($lines[0])->toContain('https://www.example.com/?page_id=1');
});

it('writes nothing to the debug log for a veto when debug logging is off', function (): void {
    $log = tempnam(sys_get_temp_dir(), 'kntnt-log-');
    $previous = ini_set('error_log', $log);

    try {
        redirect_guard(logs_vetoes: false)->veto_redirect('/dead/', 301);
    } catch (Rendered_404) {
    } finally {
        ini_set('error_log', (string) $previous);
    }
    $size = filesize($log);
    unlink($log);

    expect($size)->toBe(0);
});

it('stops the old-slug redirect to a target that is not live', function (): void {
    expect(redirect_guard(silent_page())->old_slug_post_id(1))->toBe(0)
        ->and(redirect_guard(silent_page())->old_slug_post_id(3))->toBe(0);
});

it('keeps the old-slug redirect to a published page, a live target or no post at all', function (): void {
    Functions\when('current_user_can')->justReturn(true);

    expect(redirect_guard(silent_page())->old_slug_post_id(4))->toBe(4)
        ->and(redirect_guard(silent_page())->old_slug_post_id(2))->toBe(2)
        ->and(redirect_guard(silent_page())->old_slug_post_id(0))->toBe(0);
});

it('lets a redirect issued while it renders a 404 through instead of rendering again', function (): void {
    $page = Mockery::mock(Not_Found_Page::class);
    $guard = redirect_guard($page);
    $inner = null;
    $page->expects('render')->andReturnUsing(function () use ($guard, &$inner): never {
        $inner = $guard->veto_redirect('/dead/', 302);
        throw new Rendered_404();
    });

    expect(fn () => $guard->veto_redirect('/dead/', 301))->toThrow(Rendered_404::class)
        ->and($inner)->toBe('/dead/');
});
