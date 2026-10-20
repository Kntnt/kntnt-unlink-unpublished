<?php
/**
 * Pest configuration.
 *
 * Sets up and tears down Brain Monkey around every unit test and stubs the
 * common WordPress i18n and escaping functions so namespaced plugin code that
 * calls them resolves cleanly in isolation. The HTML API that WordPress ships
 * reports misuse through _doing_it_wrong(), which is stubbed to a no-op.
 *
 * @package Tests
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;

uses()
    ->beforeEach(function (): void {
        Monkey\setUp();

        // Stub the common WordPress i18n and escaping helpers.
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('_doing_it_wrong')->justReturn(null);
    })
    ->afterEach(function (): void {
        // Count the Mockery expectations as assertions, then verify them.
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        Monkey\tearDown();
    })
    ->in('Unit');
