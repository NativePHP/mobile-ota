<?php

/**
 * Prompt mode: once per launch, check in the background and, when a release
 * is waiting, ask before downloading it.
 */

use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Nativephp\MobileOta\Events\UpdateDownloaded;
use Nativephp\MobileOta\Events\UpdateFailed;
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
            'ready_title' => 'Update ready',
            'ready_message' => 'Close and reopen the app to finish updating.',
            'downloaded_event' => UpdateDownloaded::class,
            'failed_event' => UpdateFailed::class,
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

it('leaves the download to the native side when update is tapped', function () {
    ($this->releaseWaiting)();

    // The native prompt downloads behind its own progress screen. A second
    // download from PHP would run it twice, and on an EDGE screen would hold
    // the PHP thread for as long as the download takes.
    event(new UpdatePromptAnswered(1, 'Update', Ota::PROMPT_ID));

    expect($this->bridge->callsTo('Ota.Check'))->toBeEmpty()
        ->and($this->bridge->callsTo('Ota.Download'))->toBeEmpty()
        ->and(app(Ota::class)->answerPrompt(new UpdatePromptAnswered(1, 'Update', Ota::PROMPT_ID)))
        ->toBe(['accepted' => true]);
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

    expect($this->bridge->callsTo('Ota.Download'))->toBeEmpty()
        ->and(app(Ota::class)->answerPrompt(new UpdatePromptAnswered(1, 'Update', 'some-other-alert')))
        ->toBe(['accepted' => false]);
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

it('asks once per process natively, and not for a release already queued', function () {
    // PHP boots more than once per launch, so Ota::$prompted alone cannot
    // stop a second dialog. The native side keeps the flag, and skips a
    // release whose pending.zip and pending.json are already waiting.
    foreach (['ios/OtaFunctions.swift', 'android/OtaFunctions.kt'] as $file) {
        $source = file_get_contents(dirname(__DIR__).'/resources/'.$file);
        $prompt = substr($source, strpos($source, 'class Prompt'));

        expect($prompt)->toContain('claimPrompt()')
            ->and($prompt)->toContain('pendingHolds(')
            // The key the native Download writes into pending.json is the
            // one the guard reads back.
            ->and(substr_count($source, '"release_uuid"'))->toBeGreaterThanOrEqual(2);
    }
});

it('downloads on update natively on iOS, behind a screen that cannot be dismissed', function () {
    $swift = file_get_contents(dirname(__DIR__).'/resources/ios/OtaFunctions.swift');
    $prompt = substr($swift, strpos($swift, 'class Prompt'));

    expect($prompt)->toContain('OtaFunctions.downloadWithProgress(parameters: parameters)')
        // One download path: the prompt goes through Ota.Download's own code.
        ->and($swift)->toContain('return try Download().execute(parameters: parameters)')
        ->and($swift)->toContain('modalPresentationStyle = .overFullScreen')
        ->and($swift)->toContain('isModalInPresentation = true')
        // The download starts once the screen is up, never before.
        ->and($swift)->toContain('presented: { screen in');
});

it('downloads on update natively on Android, behind a dialog that cannot be cancelled', function () {
    $kotlin = file_get_contents(dirname(__DIR__).'/resources/android/OtaFunctions.kt');
    $prompt = substr($kotlin, strpos($kotlin, 'class Prompt'));

    expect($prompt)->toContain('downloadWithProgress(activity, parameters)')
        // One download path: the prompt goes through Ota.Download's own code.
        ->and($kotlin)->toContain('Download(context).execute(downloadParameters)')
        ->and($kotlin)->toContain('.setCancelable(false)')
        ->and($kotlin)->toContain('setCanceledOnTouchOutside(false)');
});

it('tells the user to reopen the app once the download is ready, and never restarts it', function () {
    foreach (['ios/OtaFunctions.swift', 'android/OtaFunctions.kt'] as $file) {
        $source = file_get_contents(dirname(__DIR__).'/resources/'.$file);
        $download = substr($source, strpos($source, 'downloadWithProgress('.(str_ends_with($file, '.swift') ? 'parameters: [String' : 'activity: FragmentActivity')));

        expect($download)->toContain('"Update ready"')
            ->and($download)->toContain('"Close and reopen the app to finish updating."')
            ->and($download)->toContain('readyTitle')
            ->and($download)->toContain('readyMessage');

        // Nothing in the plugin relaunches, reloads or kills the app.
        foreach (['exit(', 'killProcess', 'recreate()', 'finishAffinity', 'reloadWebView', 'relaunch'] as $restart) {
            expect($source)->not->toContain($restart);
        }
    }
});
