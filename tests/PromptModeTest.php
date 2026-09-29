<?php

/**
 * Prompt mode: once per launch, check in the background and, when a release
 * is waiting, ask before downloading it.
 */

use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Nativephp\MobileOta\Events\UpdatePromptAnswered;
use Nativephp\MobileOta\Ota;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (! method_exists(FakeBridge::class, 'macro')) {
        $this->markTestSkipped('This core\'s FakeBridge does not support macros.');
    }

    $this->bridge = Native::fakeBridge();

    config([
        'nativephp-ota.mode' => 'prompt',
        'nativephp-ota.endpoint' => 'https://bifrost.nativephp.com',
        'nativephp-ota.project_uuid' => 'project-uuid',
        'nativephp-ota.arc' => 'production',
        'nativephp-ota.shell_fingerprint' => str_repeat('a', 64),
        'nativephp-ota.shell_built_at' => '2026-09-28T20:00:00+00:00',
    ]);

    (new ReflectionProperty(Ota::class, 'prompted'))->setValue(null, false);

    $this->releaseWaiting = fn () => $this->bridge->respondTo('Ota.Check', [
        'available' => true,
        'upToDate' => false,
        'release' => 'new-release',
        'sha256' => str_repeat('d', 64),
        'size' => 12009013,
        'published_at' => '2026-09-28T21:54:45+00:00',
        'download_url' => 'https://example.com/laravel_bundle.zip',
        'url' => 'https://example.com/laravel_bundle.zip',
    ]);
});

it('hands the check and the question to the native side, with who is asking', function () {
    $this->bridge->respondTo('Ota.Prompt', ['scheduled' => true]);

    expect(app(Ota::class)->prompt())->toBe(['scheduled' => true]);

    // The native side checks in the background and waits for the app to be
    // on screen, so nothing here blocks on the network or on a dialog.
    expect($this->bridge->callsTo('Ota.Check'))->toBeEmpty()
        ->and($this->bridge->callsTo('Ota.Prompt')[0]['params'])->toMatchArray([
            'endpoint' => 'https://bifrost.nativephp.com',
            'project' => 'project-uuid',
            'arc' => 'production',
            'fingerprint' => str_repeat('a', 64),
            'shell_built_at' => '2026-09-28T20:00:00+00:00',
            'title' => 'Update available',
            'buttons' => ['Later', 'Update'],
            'id' => Ota::PROMPT_ID,
            'event' => UpdatePromptAnswered::class,
        ]);
});

it('asks once per launch', function () {
    app(Ota::class)->onLaunch();
    app(Ota::class)->onLaunch();

    expect($this->bridge->callsTo('Ota.Prompt'))->toHaveCount(1);
});

it('never asks in manual mode', function () {
    config(['nativephp-ota.mode' => 'manual']);

    app(Ota::class)->onLaunch();

    expect($this->bridge->callsTo('Ota.Prompt'))->toBeEmpty()
        ->and($this->bridge->callsTo('Ota.Check'))->toBeEmpty();
});

it('downloads the release when update is tapped', function () {
    ($this->releaseWaiting)();
    $this->bridge->respondTo('Ota.Download', ['success' => true, 'queued' => true]);
    $this->bridge->respondTo('Ota.Apply', ['success' => true, 'queued' => true, 'applyOnNextBoot' => true]);

    event(new UpdatePromptAnswered(1, 'Update', Ota::PROMPT_ID));

    expect($this->bridge->callsTo('Ota.Download')[0]['params'])->toMatchArray([
        'url' => 'https://example.com/laravel_bundle.zip',
        'release' => 'new-release',
    ])->and($this->bridge->callsTo('Ota.Apply'))->toHaveCount(1);
});

it('leaves the release for next launch when later is tapped', function () {
    ($this->releaseWaiting)();

    event(new UpdatePromptAnswered(0, 'Later', Ota::PROMPT_ID));

    expect($this->bridge->callsTo('Ota.Check'))->toBeEmpty()
        ->and($this->bridge->callsTo('Ota.Download'))->toBeEmpty();
});

it('ignores a tap on one of the app\'s own alerts', function () {
    ($this->releaseWaiting)();

    event(new UpdatePromptAnswered(1, 'Update', 'some-other-alert'));

    expect($this->bridge->callsTo('Ota.Download'))->toBeEmpty();
});

it('declares the native prompt it relies on', function () {
    $manifest = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true);
    $prompt = collect($manifest['bridge_functions'])->firstWhere('name', 'Ota.Prompt');

    expect($prompt)->toMatchArray([
        'android' => 'com.nativephp.plugins.mobile_ota.OtaFunctions.Prompt',
        'ios' => 'OtaFunctions.Prompt',
    ])
        ->and(file_get_contents(dirname(__DIR__).'/resources/ios/OtaFunctions.swift'))->toContain('class Prompt: BridgeFunction')
        ->and(file_get_contents(dirname(__DIR__).'/resources/android/OtaFunctions.kt'))->toContain('class Prompt(private val activity: FragmentActivity)');
});

it('reads the native check result whether or not core wraps it in data', function () {
    // Core 4.x returns bridge data as-is; older cores wrapped it in "data".
    // Requiring the wrapper meant the prompt never showed on 4.x.
    $swift = file_get_contents(dirname(__DIR__).'/resources/ios/OtaFunctions.swift');
    $kotlin = file_get_contents(dirname(__DIR__).'/resources/android/OtaFunctions.kt');

    expect($swift)->toContain('let data = (check["data"] as? [String: Any]) ?? check')
        ->and($kotlin)->toContain('val data = check["data"] as? Map<*, *> ?: check');
});
