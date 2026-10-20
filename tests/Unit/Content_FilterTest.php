<?php
/**
 * Unit tests for Content_Filter's fast path and block rules.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Unlink_Unpublished\Content_Filter;
use Kntnt\Unlink_Unpublished\Href_Normaliser;
use Kntnt\Unlink_Unpublished\Index;
use Kntnt\Unlink_Unpublished\Index_Store;
use Kntnt\Unlink_Unpublished\Link_Rewriter;
use Kntnt\Unlink_Unpublished\Liveness;

beforeEach(function (): void {
    Functions\when('wp_parse_url')->alias('parse_url');
    Functions\when('get_current_user_id')->justReturn(0);
    Functions\when('get_post')->justReturn(null);
    Functions\when('current_user_can')->justReturn(false);
});

/** A filter over an index with one unpublished and one private target. */
function content_filter(?Link_Rewriter $rewriter = null): Content_Filter
{
    $store = Mockery::mock(Index_Store::class);
    $store->allows('index')->andReturn(Index::from_array([
        'paths' => ['/dead' => [1, 'unpublished', true], '/private' => [2, 'private', false]],
        'ids' => [1 => ['unpublished', true], 2 => ['private', false]],
    ]));

    return new Content_Filter($store, new Liveness(), new Href_Normaliser('https://www.example.com'), $rewriter ?? new Link_Rewriter());
}

it('returns a fragment without candidates byte-identical and never parses it', function (): void {
    $rewriter = Mockery::mock(Link_Rewriter::class);
    $rewriter->expects('rewrite')->never();
    $html = "<p>Live <a href=\"https://www.example.com/live/\">link</a> and <a href='https://other.example/dead/'>external</a>.</p>\n";

    expect(content_filter($rewriter)->filter_html($html))->toBe($html)
        ->and(content_filter($rewriter)->filter_html('<p>No links at all.</p>'))->toBe('<p>No links at all.</p>');
});

it('parses a fragment with a link that is not live', function (): void {
    $html = '<p>See <a href="https://example.com/dead/">this</a> and <a href="/?page_id=2">that</a>.</p>';

    expect(content_filter()->filter_html($html))->toBe('<p>See this and that.</p>');
});

it('decodes entities in hrefs before matching them', function (): void {
    $html = '<p><a href="/?p=1&amp;preview=true">x</a></p>';

    expect(content_filter()->filter_html($html))->toBe('<p>x</p>');
});

it('parses a fragment with a drop marker even when every link is live', function (): void {
    $html = '<p><span class="kntnt-unlink-unpublished-drop">Read <a href="/live/">more</a>.</span></p>';

    expect(content_filter()->filter_html($html))->toBe('<p>Read <a href="/live/">more</a>.</p>');
});

it('drops a button block whose link is not live and the buttons block it empties', function (): void {
    $filter = content_filter();
    $button = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/dead/">Boka</a></div>';
    $buttons = '<div class="wp-block-buttons"></div>';

    $filter->open_block(null, ['blockName' => 'core/buttons']);
    $filter->open_block(null, ['blockName' => 'core/button']);
    $first = $filter->render_block($button, ['blockName' => 'core/button', 'attrs' => []]);
    $second = $filter->render_block($buttons, ['blockName' => 'core/buttons', 'attrs' => []]);

    expect($first)->toBe('')
        ->and($second)->toBe('');
});

it('drops a marked block when a link that is not live rendered inside it', function (): void {
    $filter = content_filter();

    $filter->open_block(null, ['blockName' => 'core/group']);
    $filter->open_block(null, ['blockName' => 'core/paragraph']);
    $inner = $filter->render_block('<p>Läs <a href="/dead/">mer</a></p>', ['blockName' => 'core/paragraph', 'attrs' => []]);
    $outer = $filter->render_block('<div class="wp-block-group kntnt-unlink-unpublished-drop">' . $inner . '</div>', ['blockName' => 'core/group', 'attrs' => ['className' => 'kntnt-unlink-unpublished-drop']]);

    expect($inner)->toBe('<p>Läs mer</p>')
        ->and($outer)->toBe('');
});

it('keeps a marked block whose links are all live and leaves its siblings alone', function (): void {
    $filter = content_filter();
    $card = '<div class="wp-block-group kntnt-unlink-unpublished-drop"><p><a href="/live/">mer</a></p></div>';

    $filter->open_block(null, ['blockName' => 'core/group']);
    $kept = $filter->render_block($card, ['blockName' => 'core/group', 'attrs' => ['className' => 'kntnt-unlink-unpublished-drop']]);
    $filter->open_block(null, ['blockName' => 'core/group']);
    $dropped = $filter->render_block('<div class="kntnt-unlink-unpublished-drop"><a href="/dead/">x</a></div>', ['blockName' => 'core/group', 'attrs' => ['className' => 'kntnt-unlink-unpublished-drop']]);

    expect($kept)->toBe($card)
        ->and($dropped)->toBe('');
});

it('drops classic menu items that are not live and keeps a parent as a placeholder', function (): void {
    $item = static fn (int $id, string $url, int $parent = 0): object => (object) ['ID' => $id, 'url' => $url, 'menu_item_parent' => (string) $parent, 'target' => '_blank', 'xfn' => 'nofollow'];
    $items = [1 => $item(10, '/live/'), 2 => $item(11, '/dead/'), 3 => $item(12, '/?page_id=1'), 4 => $item(13, '/live/', 12)];

    $kept = content_filter()->filter_menu_items($items);

    expect(array_keys($kept))->toBe([1, 3, 4])
        ->and($kept[3]->url)->toBe('')
        ->and($kept[3]->target)->toBe('')
        ->and($kept[3]->xfn)->toBe('')
        ->and($kept[1]->url)->toBe('/live/');
});
