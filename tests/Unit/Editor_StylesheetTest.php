<?php
/**
 * Unit tests for Editor_Stylesheet (spec §9).
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Unlink_Unpublished\Editor_Stylesheet;
use Kntnt\Unlink_Unpublished\Href_Normaliser;
use Kntnt\Unlink_Unpublished\Index;

beforeEach(function (): void {
    Functions\when('wp_parse_url')->alias('parse_url');
});

function stylesheet_css(array $paths, array $ids = []): string
{
    return (new Editor_Stylesheet(new Href_Normaliser('https://www.example.com')))->css(Index::from_array(['paths' => $paths, 'ids' => $ids]));
}

it('marks every URL variant of an unpublished target', function (): void {
    $css = stylesheet_css(['/praktiskt/nodkontakt' => [1, 'unpublished', true]]);

    expect($css)->toContain('a[href$="//www.example.com/praktiskt/nodkontakt/" i]')
        ->and($css)->toContain('a[href$="//example.com/praktiskt/nodkontakt" i]')
        ->and($css)->toContain('a[href*="//example.com/praktiskt/nodkontakt/?" i]')
        ->and($css)->toContain('a[href="/praktiskt/nodkontakt/" i]')
        ->and($css)->toContain('a[href^="/praktiskt/nodkontakt#" i]')
        ->and($css)->toContain('"Unpublished"');
});

it('marks the three states differently', function (): void {
    $css = stylesheet_css([
        '/utkast' => [1, 'unpublished', false],
        '/privat' => [2, 'private', false],
        '/raderad' => [3, 'deleted', true],
    ]);

    expect($css)->toContain('"Unpublished"')
        ->and($css)->toContain('"Private"')
        ->and($css)->toContain('"Deleted"')
        ->and($css)->toContain('wavy');
});

it('marks the ID forms of a target', function (): void {
    $css = stylesheet_css([], [12 => ['unpublished', false]]);

    expect($css)->toContain('a[href$="?page_id=12" i]')
        ->and($css)->toContain('a[href*="&p=12&" i]');
});

it('writes both the encoded and the raw form of a non-ASCII path', function (): void {
    $css = stylesheet_css(['/nödkontakt' => [1, 'unpublished', true]]);

    expect($css)->toContain('a[href="/n%c3%b6dkontakt/" i]')
        ->and($css)->toContain('a[href="/nödkontakt/" i]');
});

it('escapes quotes and backslashes in attribute values', function (): void {
    $css = stylesheet_css(['/a"b\\c' => [1, 'unpublished', false]]);

    expect($css)->toContain('a[href="/a\\"b\\\\c" i]');
});

it('styles drop markers and says when a marked block is hidden now', function (): void {
    $css = stylesheet_css(['/utkast' => [1, 'unpublished', false]]);

    expect($css)->toContain('span.kntnt-unlink-unpublished-drop')
        ->and($css)->toContain('"Hidden if target unpublished"')
        ->and($css)->toContain(':has(')
        ->and($css)->toContain('"Hidden now"');
});

it('styles drop markers without a hidden-now rule when nothing is hidden', function (): void {
    $css = stylesheet_css([]);

    expect($css)->toContain('"Hidden if target unpublished"')
        ->and($css)->not->toContain(':has(');
});
