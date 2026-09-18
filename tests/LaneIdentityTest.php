<?php

/**
 * What the shell tells Bifrost about itself: which app, which arc, the
 * fingerprint it was built against and the release it already holds.
 */

use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Nativephp\MobileOta\Ota;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (! method_exists(FakeBridge::class, 'macro')) {
        $this->markTestSkipped('This core\'s FakeBridge does not support macros.');
    }

    $this->bridge = Native::fakeBridge();

    config([
        'nativephp-ota.endpoint' => 'https://bifrost.nativephp.com',
        'nativephp-ota.project_uuid' => 'project-uuid',
        'nativephp-ota.arc' => 'staging',
        'nativephp-ota.shell_fingerprint' => str_repeat('a', 64),
        'nativephp-ota.fingerprint_algorithm' => 1,
    ]);

    $this->manifest = base_path('ota.json');
});

afterEach(function () {
    @unlink($this->manifest);
});

it('describes the shell it shipped with when no update has been applied', function () {
    expect(app(Ota::class)->identity())->toMatchArray([
        'release_uuid' => null,
        'arc' => 'staging',
        'shell_fingerprint' => str_repeat('a', 64),
        'fingerprint_algorithm' => '1',
    ]);
});

it('reads the identity a payload stamped into the environment', function () {
    // A payload ships its own .env, so once one is applied the app's
    // configuration *is* the payload's identity.
    config([
        'nativephp-ota.release_uuid' => '01a0b243-de72-7057-b366-f72a7ae05971',
        'nativephp-ota.shell_fingerprint' => str_repeat('b', 64),
        'nativephp-ota.fingerprint_algorithm' => 2,
    ]);

    expect(app(Ota::class)->identity())->toMatchArray([
        'release_uuid' => '01a0b243-de72-7057-b366-f72a7ae05971',
        'shell_fingerprint' => str_repeat('b', 64),
        'fingerprint_algorithm' => '2',
    ])->and(app(Ota::class)->currentRelease())->toBe('01a0b243-de72-7057-b366-f72a7ae05971');
});

it('falls back to the manifest for a payload published before the builder stamped it', function () {
    config(['nativephp-ota.release_uuid' => null, 'nativephp-ota.shell_fingerprint' => null]);

    file_put_contents($this->manifest, json_encode([
        'release_uuid' => 'older-release',
        'shell_fingerprint' => str_repeat('c', 64),
    ]));

    expect(app(Ota::class)->identity())->toMatchArray([
        'release_uuid' => 'older-release',
        'shell_fingerprint' => str_repeat('c', 64),
    ]);
});

it('prefers the environment over a manifest left behind', function () {
    config(['nativephp-ota.release_uuid' => 'from-env']);
    file_put_contents($this->manifest, json_encode(['release_uuid' => 'stale-manifest']));

    expect(app(Ota::class)->currentRelease())->toBe('from-env');
});

it('asks with the identity rather than a version', function () {
    config([
        'nativephp-ota.release_uuid' => 'held-release',
        'nativephp-ota.shell_fingerprint' => str_repeat('c', 64),
    ]);

    $this->bridge->respondTo('Ota.Check', ['available' => false, 'upToDate' => true]);

    app(Ota::class)->check();

    $parameters = $this->bridge->callsTo('Ota.Check')[0]['params'];

    expect($parameters)->toMatchArray([
        'project' => 'project-uuid',
        'arc' => 'staging',
        'fingerprint' => str_repeat('c', 64),
        'algorithm' => '1',
        'release' => 'held-release',
    ]);
});

it('hands the download the checksum to verify before queueing', function () {
    $this->bridge->respondTo('Ota.Check', [
        'available' => true,
        'upToDate' => false,
        'release' => 'new-release',
        'sha256' => str_repeat('d', 64),
        'size' => 12009013,
        'download_url' => 'https://example.com/laravel_bundle.zip',
        'url' => 'https://example.com/laravel_bundle.zip',
    ]);
    $this->bridge->respondTo('Ota.Download', ['success' => true, 'queued' => true]);
    $this->bridge->respondTo('Ota.Apply', ['success' => true, 'queued' => true, 'applyOnNextBoot' => true]);

    app(Ota::class)->downloadAndApply();

    $parameters = $this->bridge->callsTo('Ota.Download')[0]['params'];

    expect($parameters)->toMatchArray([
        'url' => 'https://example.com/laravel_bundle.zip',
        'sha256' => str_repeat('d', 64),
        'size' => 12009013,
    ]);
});

it('falls back to the payload manifest for the app it belongs to', function () {
    config(['nativephp-ota.project_uuid' => null]);

    file_put_contents($this->manifest, json_encode([
        'app_id' => 'f2ff44c1-7e1c-42cc-9ef9-08482ff12193',
        'release_uuid' => 'held-release',
        'arc' => 'staging',
        'shell_fingerprint' => str_repeat('e', 64),
    ]));

    $this->bridge->respondTo('Ota.Check', ['available' => false, 'upToDate' => true]);

    app(Ota::class)->check();

    expect(app(Ota::class)->identity()['project_uuid'])->toBe('f2ff44c1-7e1c-42cc-9ef9-08482ff12193')
        ->and($this->bridge->callsTo('Ota.Check')[0]['params']['project'])->toBe('f2ff44c1-7e1c-42cc-9ef9-08482ff12193');
});

it('prefers configuration over the manifest for the app id', function () {
    config(['nativephp-ota.project_uuid' => 'configured-uuid']);
    file_put_contents($this->manifest, json_encode(['app_id' => 'manifest-uuid']));

    expect(app(Ota::class)->identity()['project_uuid'])->toBe('configured-uuid');
});
