<?php
/**
 * End-to-end tests in WordPress Playground, wrapped for Pest.
 *
 * Each test drives the same script that run-tests.sh Level 2 and the CI e2e
 * job use, so the Integration suite and the shell entry point share one
 * source of truth. Because they download and run a WASM WordPress build over
 * the network, they are skipped unless KNTNT_RUN_PLAYGROUND=1 is set; a plain
 * `pest` run stays fast and offline.
 *
 * @package Tests\Integration
 * @since   0.1.0
 */

declare(strict_types=1);

dataset('playground drivers', [
    'boot' => ['bash', 'playground-smoke.sh'],
    'rendering' => ['python3', 'playground-rendering.py'],
    'bogo' => ['python3', 'playground-bogo.py'],
    'polylang' => ['python3', 'playground-polylang.py'],
    'slim seo' => ['python3', 'playground-slim-seo.py'],
]);

it('passes in WordPress Playground on PHP 8.4', function (string $runner, string $script): void {
    if (getenv('KNTNT_RUN_PLAYGROUND') !== '1') {
        $this->markTestSkipped('Set KNTNT_RUN_PLAYGROUND=1 (or run `bash run-tests.sh`) to run the Playground e2e.');
    }

    $output = [];
    $exit_code = 0;
    exec($runner . ' ' . escapeshellarg(__DIR__ . '/' . $script) . ' 2>&1', $output, $exit_code);

    expect($exit_code)->toBe(0, implode("\n", $output));
})->with('playground drivers')->group('e2e');
