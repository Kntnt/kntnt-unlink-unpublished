<?php
/**
 * Unit tests for the link surgery (spec §4.2 and §4.3).
 *
 * Every behaviour runs against both surgeons: the tree surgeon built on
 * WP_HTML_Processor and the lexical surgeon built on WP_HTML_Tag_Processor,
 * which the rewriter falls back to when the HTML Processor cannot parse a
 * fragment. The verdict maps hrefs to live (true) or not live (false); any
 * other href is not a target.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Kntnt\Unlink_Unpublished\Lexical_Surgeon;
use Kntnt\Unlink_Unpublished\Link_Rewriter;
use Kntnt\Unlink_Unpublished\Surgery_Result;
use Kntnt\Unlink_Unpublished\Tree_Surgeon;

const DEAD = '/dead/';
const LIVE = '/live/';

/** Verdict used throughout: DEAD is not live, LIVE is live, anything else is no target. */
function verdict(): callable
{
    return static fn(string $href): ?bool => match ($href) {
        DEAD, '/dead-2/' => false,
        LIVE => true,
        default => null,
    };
}

dataset('surgeons', [
    'tree' => [static fn(string $html, bool $label = false): Surgery_Result => Tree_Surgeon::create_fragment($html)->operate(verdict(), $label)],
    'lexical' => [static fn(string $html, bool $label = false): Surgery_Result => (new Lexical_Surgeon($html))->operate(verdict(), $label)],
]);

it('unlinks an inline link and leaves every other byte alone', function (callable $operate): void {
    $html = '<p>Se <a href="/dead/" title="Logi">Logi och mat</a>. Och <a href="/live/?a=1&#038;b=2" >här</a>.<br /></p>';

    $result = $operate($html);

    expect($result->html)->toBe('<p>Se Logi och mat. Och <a href="/live/?a=1&#038;b=2" >här</a>.<br /></p>')
        ->and($result->not_live)->toBe(1)
        ->and($result->changed)->toBeTrue();
})->with('surgeons');

it('returns a fragment without links that are not live exactly as it came in', function (callable $operate): void {
    $html = "<p>A <a href='/live/'>live</a> and <a href=\"https://elsewhere.example/\">external</a> link.</p>\n";

    $result = $operate($html);

    expect($result->html)->toBe($html)
        ->and($result->changed)->toBeFalse()
        ->and($result->not_live)->toBe(0);
})->with('surgeons');

it('unlinks an image link and keeps the image', function (callable $operate): void {
    $html = '<figure class="wp-block-image"><a href="/dead/"><img src="/x.jpg" alt=""/></a></figure>';

    expect($operate($html)->html)->toBe('<figure class="wp-block-image"><img src="/x.jpg" alt=""/></figure>');
})->with('surgeons');

it('drops an icon-only link, even when the icon carries a title', function (callable $operate): void {
    $html = '<div>Icons: <a href="/dead/"><svg viewBox="0 0 1 1"><title>Go</title><path d="M0 0"/></svg></a></div>';

    expect($operate($html)->html)->toBe('<div>Icons: </div>');
})->with('surgeons');

it('drops an empty overlay link and an icon-font link', function (callable $operate): void {
    $html = '<div class="card"><h3>Title</h3><a class="stretched" href="/dead/"></a><a href="/dead-2/"><i class="icon-arrow"></i></a></div>';

    expect($operate($html)->html)->toBe('<div class="card"><h3>Title</h3></div>');
})->with('surgeons');

it('unlinks a link whose only text is visually hidden', function (callable $operate): void {
    $html = '<div><a href="/dead/"><span class="screen-reader-text">Read more</span></a></div>';

    expect($operate($html)->html)->toBe('<div><span class="screen-reader-text">Read more</span></div>');
})->with('surgeons');

it('drops button-styled links by their classes', function (callable $operate): void {
    $html = '<div><a class="wp-block-button__link wp-element-button" href="/dead/">Boka</a><a class="x wp-element-button" href="/dead-2/">Läs</a></div>';

    expect($operate($html)->html)->toBe('<div></div>');
})->with('surgeons');

it('drops a marked span with its text when a link inside is not live', function (callable $operate): void {
    $html = '<li>Ta med mat. <span class="kntnt-unlink-unpublished-drop">Se <a href="/dead/">Logi och mat</a>.</span></li>';

    expect($operate($html)->html)->toBe('<li>Ta med mat. </li>');
})->with('surgeons');

it('unwraps a marked span whose links are all live', function (callable $operate): void {
    $html = '<p>Hund. <span class="kntnt-unlink-unpublished-drop">Läs mer under <a href="/live/">Nödkontakt</a>.</span></p>';

    $result = $operate($html);

    expect($result->html)->toBe('<p>Hund. Läs mer under <a href="/live/">Nödkontakt</a>.</p>')
        ->and($result->not_live)->toBe(0);
})->with('surgeons');

it('drops a marked element in classic content and keeps it when its links are live', function (callable $operate): void {
    $dead = '<div class="card kntnt-unlink-unpublished-drop"><h3>Banor</h3><p><a href="/dead/">Läs mer</a></p></div><p>Kvar</p>';
    $live = '<div class="card kntnt-unlink-unpublished-drop"><h3>Banor</h3><p><a href="/live/">Läs mer</a></p></div>';

    expect($operate($dead)->html)->toBe('<p>Kvar</p>')
        ->and($operate($live)->html)->toBe($live);
})->with('surgeons');

it('drops an outer marked element whole when an inner marked element holds a dead link', function (callable $operate): void {
    $html = '<div class="kntnt-unlink-unpublished-drop">Outer <span class="kntnt-unlink-unpublished-drop">inner <a href="/dead/">x</a></span></div><p>After</p>';

    expect($operate($html)->html)->toBe('<p>After</p>');
})->with('surgeons');

it('removes a paragraph or list item that a drop left without visible content', function (callable $operate): void {
    $html = '<p><span class="kntnt-unlink-unpublished-drop">Se <a href="/dead/">Logi</a>.</span></p><ul><li><a href="/dead-2/"><svg></svg></a></li></ul><p>Rest</p>';

    expect($operate($html)->html)->toBe('<p>Rest</p>');
})->with('surgeons');

it('keeps containers that were empty before and lists with items left', function (callable $operate): void {
    $html = '<p></p><ul><li><a href="/dead/"></a></li><li>Kvar</li></ul>';

    expect($operate($html)->html)->toBe('<p></p><ul><li>Kvar</li></ul>');
})->with('surgeons');

it('turns a submenu label into a placeholder link and handles the children as links', function (callable $operate): void {
    $html = '<li class="wp-block-navigation-submenu"><a class="wp-block-navigation-item__content" href="/dead/" target="_blank" rel="noopener">Banor</a>'
        . '<ul><li><a class="wp-block-navigation-item__content" href="/dead-2/">40 km</a></li><li><a class="wp-block-navigation-item__content" href="/live/">20 km</a></li></ul></li>';

    $result = $operate($html, true);

    expect($result->html)->toBe('<li class="wp-block-navigation-submenu"><a class="wp-block-navigation-item__content"   >Banor</a>'
        . '<ul><li>40 km</li><li><a class="wp-block-navigation-item__content" href="/live/">20 km</a></li></ul></li>')
        ->and($result->not_live)->toBe(2);
})->with('surgeons');

it('counts every link that is not live', function (callable $operate): void {
    $html = '<p><a href="/dead/">a</a> <a href="/dead-2/">b</a> <a href="/live/">c</a></p>';

    expect($operate($html)->not_live)->toBe(2);
})->with('surgeons');

it('is idempotent', function (callable $operate): void {
    $html = '<p>Se <a href="/dead/">x</a>. <span class="kntnt-unlink-unpublished-drop"><a href="/live/">y</a></span></p>';

    $once = $operate($html)->html;

    expect($operate($once)->html)->toBe($once)
        ->and($operate($once)->changed)->toBeFalse();
})->with('surgeons');

it('falls back to the lexical surgeon when the HTML Processor cannot parse a fragment', function (): void {
    $html = '<table><tr><td><a href="/dead/">x</a></td></tr><a href="/dead-2/">y</a></table>';

    $tree = Tree_Surgeon::create_fragment($html)?->operate(verdict());
    $result = (new Link_Rewriter())->rewrite($html, verdict());

    expect($tree)->toBeNull()
        ->and($result->html)->not->toContain('href="/dead/"')
        ->and($result->html)->not->toContain('href="/dead-2/"');
});
