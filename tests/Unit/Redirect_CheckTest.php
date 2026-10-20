<?php
/**
 * Unit tests for Redirect_Check (spec §10.2).
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Unlink_Unpublished\Redirect_Check;

beforeEach(function (): void {
    $this->stored = [];
    $options = &$this->stored;
    Functions\when('get_option')->alias(function (string $name, mixed $default = false) use (&$options): mixed {
        return $options[$name] ?? $default;
    });
    Functions\when('update_option')->alias(function (string $name, mixed $value) use (&$options): bool {
        $options[$name] = $value;
        return true;
    });
    Functions\when('get_post_stati')->justReturn(['publish' => 'publish']);
    Functions\when('get_post')->alias(fn (int $id): ?WP_Post => $id === 7 ? new WP_Post(['ID' => 7, 'post_status' => 'publish']) : null);
    Functions\when('get_permalink')->justReturn('https://example.com/schema/');
    Functions\when('is_wp_error')->alias(fn (mixed $thing): bool => $thing instanceof WP_Error);
    Functions\when('wp_remote_retrieve_response_code')->alias(fn (array $response): int => $response['response']['code']);
    Functions\when('wp_remote_retrieve_header')->alias(fn (array $response, string $header): string => $response['headers'][$header] ?? '');
});

/** Makes the loopback answer with a response. */
function answer(mixed $response): void
{
    Functions\when('wp_remote_head')->alias(function (string $url, array $args) use ($response): mixed {
        expect($args['redirection'])->toBe(0);
        return $response;
    });
}

if (!class_exists('WP_Error')) {
    class WP_Error {}
}

it('records a published page whose address redirects', function (): void {
    answer(['response' => ['code' => 302], 'headers' => ['location' => 'https://example.com/']]);

    (new Redirect_Check())->check(7);

    expect(Redirect_Check::records()[7])->toMatchArray(['status' => 'redirect', 'code' => 302, 'location' => 'https://example.com/']);
});

it('clears the record when a later check returns 200', function (): void {
    answer(['response' => ['code' => 301], 'headers' => ['location' => '/']]);
    (new Redirect_Check())->check(7);
    answer(['response' => ['code' => 200], 'headers' => []]);

    (new Redirect_Check())->check(7);

    expect(Redirect_Check::records())->toBe([]);
});

it('records could not check when the loopback fails', function (): void {
    answer(new WP_Error());

    (new Redirect_Check())->check(7);

    expect(Redirect_Check::records()[7]['status'])->toBe('unchecked');
});

it('forgets a page that is no longer published', function (): void {
    answer(['response' => ['code' => 302], 'headers' => ['location' => '/']]);
    (new Redirect_Check())->check(7);
    Functions\when('get_post')->justReturn(new WP_Post(['ID' => 7, 'post_status' => 'draft']));

    (new Redirect_Check())->check(7);

    expect(Redirect_Check::records())->toBe([]);
});

it('re-checks every recorded page', function (): void {
    answer(['response' => ['code' => 302], 'headers' => ['location' => '/']]);
    (new Redirect_Check())->check(7);
    answer(['response' => ['code' => 200], 'headers' => []]);

    (new Redirect_Check())->recheck();

    expect(Redirect_Check::records())->toBe([]);
});
