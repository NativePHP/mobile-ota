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

it('describes the payload it is running once one has been applied', function () {
    file_put_contents($this->manifest, json_encode([
        'release_uuid' => '01a0b243-de72-7057-b366-f72a7ae05971',
        'arc' => 'staging',
        'shell_fingerprint' => str_repeat('b', 64),
        'fingerprint_algorithm' => '2',
    ]));

    expect(app(Ota::class)->identity())->toMatchArray([
        'release_uuid' => '01a0b243-de72-7057-b366-f72a7ae05971',
        'shell_fingerprint' => str_repeat('b', 64),
        'fingerprint_algorithm' => '2',
    ])->and(app(Ota::class)->currentRelease())->toBe('01a0b243-de72-7057-b366-f72a7ae05971');
});

it('asks with the identity rather than a version', function () {
    file_put_contents($this->manifest, json_encode([
        'release_uuid' => 'held-release',
        'arc' => 'staging',
        'shell_fingerprint' => str_repeat('c', 64),
        'fingerprint_algorithm' => '1',
    ]));

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
