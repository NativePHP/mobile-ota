<?php

namespace Nativephp\MobileOta\Testing;

use Native\Mobile\Testing\FakeBridge;
use PHPUnit\Framework\Assert;

/**
 * OTA test vocabulary for the NativePHP testing suite, registered as
 * FakeBridge macros so app tests read in update terms instead of raw
 * bridge method strings:
 *
 *     Native::fakeBridge()->withOtaUpdate('3.0.13.0.3', 'https://example.com/laravel_bundle.zip');
 *
 *     Native::test(Home::class)
 *         ->tap('Check OTA')
 *         ->assertOtaChecked()
 *         ->tap('Download & queue')
 *         ->assertOtaDownloaded()
 *         ->assertOtaQueued();
 *
 * Check/download/apply/rollback are otherwise fire-and-forget from PHP
 * (core extracts pending.zip on the next boot), so those flows get
 * assert* vocabulary. The payload the app reads back (check / status)
 * gets with* scripting helpers.
 *
 * Registered by OtaServiceProvider when the app is running unit tests
 * on a core whose FakeBridge supports macros.
 */
class OtaMacros
{
    public static function register(): void
    {
        /**
         * Script an available OTA. $version is the Bifrost current_version
         * string; $url is the download_url the native Check/Download use.
         */
        FakeBridge::macro('withOtaUpdate', function (string $version = '3.0.13.0.3', string $url = 'https://example.com/laravel_bundle.zip') {
            return $this->respondTo('Ota.Check', [
                'available' => true,
                'upToDate' => false,
                'current_version' => $version,
                'version' => $version,
                'download_url' => $url,
                'url' => $url,
            ]);
        });

        /** Script a check that reports the device is already on the latest payload. */
        FakeBridge::macro('withOtaUpToDate', function (string $version = '1.0.0') {
            return $this->respondTo('Ota.Check', [
                'available' => false,
                'upToDate' => true,
                'current_version' => $version,
                'version' => $version,
            ]);
        });

        /** Script a check that never hits the network because the device is offline. */
        FakeBridge::macro('withOtaOffline', function () {
            return $this->respondTo('Ota.Check', [
                'available' => false,
                'reason' => 'offline',
            ]);
        });

        /**
         * Script GetStatus. Defaults to a clean install with nothing pending.
         */
        FakeBridge::macro('withOtaStatus', function (
            int|string $version = '1.0.0',
            bool $pending = false,
            bool $queued = false,
            bool $hasPrevious = false,
        ) {
            return $this->respondTo('Ota.GetStatus', [
                'version' => $version,
                'current_version' => (string) $version,
                'pending' => $pending,
                'queued' => $queued,
                'applyOnNextBoot' => $queued,
                'hasPrevious' => $hasPrevious,
            ]);
        });

        /** Assert Ota::check() / Ota.Check ran. */
        FakeBridge::macro('assertOtaChecked', function () {
            return $this->assertCalled('Ota.Check');
        });

        /**
         * Assert a download was started — any URL, or exactly $url when given
         * (Ota::downloadAndApply() / Ota.Download).
         */
        FakeBridge::macro('assertOtaDownloaded', function (?string $url = null) {
            if ($url === null) {
                return $this->assertCalled('Ota.Download');
            }

            $urls = array_map(
                fn (array $call) => $call['params']['url'] ?? '',
                $this->callsTo('Ota.Download')
            );

            Assert::assertContains(
                $url,
                $urls,
                "Expected OTA download of [{$url}]. Downloaded: "
                    .($urls === [] ? '(nothing)' : '['.implode('], [', $urls).']')
            );

            return $this;
        });

        /** Assert the pending zip was queued for core to extract on next boot (Ota.Apply). */
        FakeBridge::macro('assertOtaQueued', function () {
            return $this->assertCalled('Ota.Apply');
        });

        /** Assert rollback re-dropped previous.zip as pending (Ota.Rollback). */
        FakeBridge::macro('assertOtaRolledBack', function () {
            return $this->assertCalled('Ota.Rollback');
        });

        /** Assert nothing was downloaded. */
        FakeBridge::macro('assertNothingOtaDownloaded', function () {
            return $this->assertNotCalled('Ota.Download');
        });
    }
}
