<?php
/**
 * Unit tests for Href_Normaliser.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Kntnt\Unlink_Unpublished\Href_Normaliser;

beforeEach(function (): void {
    Functions\when('wp_parse_url')->alias('parse_url');
});

dataset('same-site variants', [
    'absolute with www' => ['https://www.example.com/praktiskt/nodkontakt/'],
    'absolute bare host' => ['https://example.com/praktiskt/nodkontakt/'],
    'http scheme' => ['http://www.example.com/praktiskt/nodkontakt/'],
    'protocol-relative' => ['//example.com/praktiskt/nodkontakt/'],
    'root-relative' => ['/praktiskt/nodkontakt/'],
    'without trailing slash' => ['/praktiskt/nodkontakt'],
    'upper case' => ['HTTPS://WWW.EXAMPLE.COM/Praktiskt/NodKontakt/'],
    'with query' => ['/praktiskt/nodkontakt/?utm_source=x'],
    'with fragment' => ['/praktiskt/nodkontakt/#mer'],
    'query without slash' => ['/praktiskt/nodkontakt?x=1#y'],
]);

it('reduces every same-site variant to one path key', function (string $href): void {
    $normaliser = new Href_Normaliser('https://www.example.com');

    $href = $normaliser->normalise($href);

    expect($href?->path)->toBe('/praktiskt/nodkontakt')
        ->and($href?->id)->toBeNull();
})->with('same-site variants');

it('compares percent-encoded and raw characters alike', function (): void {
    $normaliser = new Href_Normaliser('https://example.com');

    $encoded = $normaliser->normalise('https://example.com/%D0%BF%D1%80%D0%B8/');
    $raw = $normaliser->normalise('/при/');
    $stored = Href_Normaliser::path_key('/%d0%bf%d1%80%d0%b8/');

    expect($encoded?->path)->toBe($stored)
        ->and($raw?->path)->toBe($stored);
});

dataset('id forms', [
    'page_id' => ['/?page_id=12', 12],
    'p' => ['/?p=12', 12],
    'post type and p' => ['/?post_type=event&p=12', 12],
    'preview link' => ['https://www.example.com/?page_id=12&preview=true', 12],
    'index.php' => ['/index.php?page_id=12', 12],
    'with fragment' => ['//example.com/?p=12#comments', 12],
]);

it('reads the post ID of an ID form whatever other parameters come with it', function (string $href, int $id): void {
    $normaliser = new Href_Normaliser('https://www.example.com/');

    expect($normaliser->normalise($href)?->id)->toBe($id);
})->with('id forms');

it('reads ID forms relative to a home URL in a subdirectory', function (): void {
    $normaliser = new Href_Normaliser('https://example.com/blog');

    expect($normaliser->normalise('/blog/?p=7')?->id)->toBe(7)
        ->and($normaliser->normalise('/?p=7')?->id)->toBeNull()
        ->and($normaliser->normalise('/blog/om-oss/')?->path)->toBe('/blog/om-oss');
});

dataset('ignored hrefs', [
    'other host' => ['https://other.example.org/praktiskt/'],
    'sub-domain' => ['https://shop.example.com/praktiskt/'],
    'mailto' => ['mailto:info@example.com'],
    'tel' => ['tel:+4612345'],
    'fragment only' => ['#mer'],
    'document-relative' => ['../logi/'],
    'bare relative' => ['logi/'],
    'query only' => ['?page_id=12'],
    'empty' => [''],
    'javascript' => ['javascript:void(0)'],
]);

it('ignores hrefs that are not same-site absolute or root-relative', function (string $href): void {
    $normaliser = new Href_Normaliser('https://www.example.com');

    expect($normaliser->normalise($href))->toBeNull();
})->with('ignored hrefs');

it('keeps the port as part of the host', function (): void {
    $normaliser = new Href_Normaliser('http://127.0.0.1:9400');

    expect($normaliser->normalise('http://127.0.0.1:9400/x/')?->path)->toBe('/x')
        ->and($normaliser->normalise('http://127.0.0.1/x/'))->toBeNull();
});

it('keeps the root path as a slash', function (): void {
    $normaliser = new Href_Normaliser('https://www.example.com');

    expect($normaliser->normalise('https://example.com')?->path)->toBe('/')
        ->and(Href_Normaliser::path_key(''))->toBe('/');
});

it('accepts the hosts a site adds through the hosts filter, with their www twins', function (): void {
    Filters\expectApplied('kntnt_unlink_unpublished_hosts')
        ->once()
        ->with(['example.com', 'www.example.com'])
        ->andReturn(['example.com', 'www.example.com', 'en.Example.org']);
    $normaliser = new Href_Normaliser('https://example.com');

    expect($normaliser->normalise('https://en.example.org/about/')?->path)->toBe('/about')
        ->and($normaliser->normalise('https://www.en.example.org/about/')?->path)->toBe('/about')
        ->and($normaliser->normalise('https://other.example.org/about/'))->toBeNull()
        ->and($normaliser->authorities())->toBe(['example.com', 'www.example.com', 'en.example.org', 'www.en.example.org']);
});
