<?php

/**
 * The OTA test vocabulary this plugin registers on the FakeBridge
 * (withOtaUpdate / withOtaUpToDate / withOtaOffline / withOtaStatus /
 * assertOtaChecked / assertOtaDownloaded / assertOtaQueued /
 * assertOtaRolledBack / assertNothingOtaDownloaded) — the sugar app
 * developers use instead of raw bridge method strings.
 *
 * Skipped on cores whose FakeBridge predates macro support.
 */

use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Nativephp\MobileOta\Ota;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (! method_exists(FakeBridge::class, 'macro')) {
        $this->markTestSkipped('This core\'s FakeBridge does not support macros.');
    }

    $this->bridge = Native::fakeBridge();
    $this->bridge->respondTo('Ota.Download', ['success' => true, 'version' => '3.0.13.0.3']);
    $this->bridge->respondTo('Ota.Apply', [
        'success' => true,
        'queued' => true,
        'applyOnNextBoot' => true,
        'restartRequired' => true,
        'version' => '3.0.13.0.3',
    ]);
    $this->bridge->respondTo('Ota.Rollback', ['success' => true, 'version' => '1.0.0']);
});

describe('withOtaUpdate()', function () {
    it('scripts the payload check() reports', function () {
        $this->bridge->withOtaUpdate('3.0.13.0.3', 'https://cdn.example/laravel_bundle.zip');

        $check = (new Ota)->check();

        expect($check['available'])->toBeTrue()
            ->and($check['current_version'])->toBe('3.0.13.0.3')
            ->and($check['url'])->toBe('https://cdn.example/laravel_bundle.zip')
            ->and($check['download_url'])->toBe('https://cdn.example/laravel_bundle.zip');
    });
});

describe('withOtaUpToDate()', function () {
    it('scripts a check with nothing to download', function () {
        $this->bridge->withOtaUpToDate('1.0.0');

        $check = (new Ota)->check();

        expect($check['available'])->toBeFalse()
            ->and($check['upToDate'])->toBeTrue();
    });
});

describe('withOtaOffline()', function () {
    it('scripts an offline check', function () {
        $this->bridge->withOtaOffline();

        expect((new Ota)->check())->toMatchArray([
            'available' => false,
            'reason' => 'offline',
        ]);
    });
});

describe('withOtaStatus()', function () {
    it('scripts the status status() reports', function () {
        $this->bridge->withOtaStatus(version: '1.0.0', pending: true, queued: true, hasPrevious: true);

        expect((new Ota)->status())->toMatchArray([
            'version' => '1.0.0',
            'current_version' => '1.0.0',
            'pending' => true,
            'queued' => true,
            'applyOnNextBoot' => true,
            'hasPrevious' => true,
        ]);
    });
});

describe('assertOtaChecked()', function () {
    it('passes after a check', function () {
        $this->bridge->withOtaUpToDate();

        (new Ota)->check();

        $this->bridge->assertOtaChecked();
    });

    it('fails when nothing was checked', function () {
        expect(fn () => $this->bridge->assertOtaChecked())
            ->toThrow(AssertionFailedError::class);
    });
});

describe('assertOtaDownloaded()', function () {
    it('passes when any download ran', function () {
        $this->bridge->withOtaUpdate('3.0.13.0.3', 'https://cdn.example/laravel_bundle.zip');

        (new Ota)->downloadAndApply();

        $this->bridge->assertOtaDownloaded();
    });

    it('matches the exact download URL', function () {
        $url = 'https://cdn.example/laravel_bundle.zip';
        $this->bridge->withOtaUpdate('3.0.13.0.3', $url);

        (new Ota)->downloadAndApply();

        $this->bridge->assertOtaDownloaded($url);
    });

    it('fails when nothing was downloaded', function () {
        expect(fn () => $this->bridge->assertOtaDownloaded())
            ->toThrow(AssertionFailedError::class);
    });

    it('fails when a different URL was downloaded, naming what was', function () {
        $this->bridge->withOtaUpdate('3.0.13.0.3', 'https://cdn.example/actual.zip');

        (new Ota)->downloadAndApply();

        expect(fn () => $this->bridge->assertOtaDownloaded('https://cdn.example/expected.zip'))
            ->toThrow(AssertionFailedError::class, 'actual.zip');
    });
});

describe('assertOtaQueued()', function () {
    it('passes after downloadAndApply queues the zip', function () {
        $this->bridge->withOtaUpdate();

        (new Ota)->downloadAndApply();

        $this->bridge->assertOtaQueued();
    });

    it('fails when apply never ran', function () {
        expect(fn () => $this->bridge->assertOtaQueued())
            ->toThrow(AssertionFailedError::class);
    });
});

describe('assertOtaRolledBack()', function () {
    it('passes after a rollback', function () {
        (new Ota)->rollback();

        $this->bridge->assertOtaRolledBack();
    });

    it('fails when nothing was rolled back', function () {
        expect(fn () => $this->bridge->assertOtaRolledBack())
            ->toThrow(AssertionFailedError::class);
    });
});

describe('assertNothingOtaDownloaded()', function () {
    it('passes when no download happened', function () {
        $this->bridge->withOtaUpToDate();

        (new Ota)->check();

        $this->bridge->assertNothingOtaDownloaded();
    });

    it('fails after a download', function () {
        $this->bridge->withOtaUpdate();

        (new Ota)->downloadAndApply();

        expect(fn () => $this->bridge->assertNothingOtaDownloaded())
            ->toThrow(AssertionFailedError::class);
    });
});

describe('downloadAndApply()', function () {
    it('does not download when the check is up to date', function () {
        $this->bridge->withOtaUpToDate();

        $result = (new Ota)->downloadAndApply();

        expect($result['available'])->toBeFalse();
        $this->bridge->assertNothingOtaDownloaded();
    });

    it('prefers url and falls back to download_url', function () {
        $this->bridge->respondTo('Ota.Check', [
            'available' => true,
            'upToDate' => false,
            'current_version' => '3.0.13.0.3',
            'download_url' => 'https://cdn.example/from-download-url.zip',
        ]);

        (new Ota)->downloadAndApply();

        $this->bridge->assertOtaDownloaded('https://cdn.example/from-download-url.zip');
    });
});
