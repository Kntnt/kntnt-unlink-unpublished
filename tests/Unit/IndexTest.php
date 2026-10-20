<?php
/**
 * Unit tests for Index.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Kntnt\Unlink_Unpublished\Href;
use Kntnt\Unlink_Unpublished\Index;
use Kntnt\Unlink_Unpublished\Target_State;

function sample_index(): Index
{
    return Index::from_array([
        'paths' => [
            '/praktiskt/nodkontakt' => [1532, 'unpublished', true],
            '/schema' => [1443, 'unpublished', false],
            '/medlemmar' => [20, 'private', false],
            '/gammal' => [99, 'deleted', true],
        ],
        'ids' => [
            1532 => ['unpublished', true],
            20 => ['private', false],
            99 => ['deleted', true],
        ],
    ]);
}

it('looks up a path key', function (): void {
    $entry = sample_index()->lookup(new Href('/praktiskt/nodkontakt'));

    expect($entry?->target_id)->toBe(1532)
        ->and($entry?->state)->toBe(Target_State::Unpublished)
        ->and($entry?->was_public)->toBeTrue();
});

it('looks up an ID form by its post ID and ignores its path', function (): void {
    $index = sample_index();

    expect($index->lookup(new Href('/', 99))?->state)->toBe(Target_State::Deleted)
        ->and($index->lookup(new Href('/', 12345)))->toBeNull();
});

it('returns null for a path that is not in the index', function (): void {
    expect(sample_index()->lookup(new Href('/praktiskt')))->toBeNull();
});

it('survives a round trip through its array form', function (): void {
    $index = sample_index();

    expect(Index::from_array($index->to_array()))->toEqual($index)
        ->and($index->is_empty())->toBeFalse()
        ->and(Index::from_array([])->is_empty())->toBeTrue();
});

it('lists its paths by state', function (): void {
    expect(sample_index()->paths(Target_State::Unpublished))->toBe(['/praktiskt/nodkontakt', '/schema'])
        ->and(sample_index()->ids(Target_State::Private))->toBe([20]);
});
