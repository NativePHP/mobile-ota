<?php

/**
 * Prompt mode: once per launch, after the first page, ask before downloading a
 * release that is waiting for this shell.
 */

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    $this->releaseWaiting = fn (string $publishedAt = '2026-09-28T21:54:45+00:00') => $this->bridge->respondTo('Ota.Check', [
        'available' => true,
        'upToDate' => false,
        'release' => 'new-release',
        'sha256' => str_repeat('d', 64),
        'size' => 12009013,
        'published_at' => $publishedAt,
        'download_url' => 'https://example.com/laravel_bundle.zip',
        'url' => 'https://example.com/laravel_bundle.zip',
    ]);

    $this->firstPage = fn () => event(new RequestHandled(Request::create('/'), new Response('ok')));
});

it('asks later or update when a release is waiting', function () {
    ($this->releaseWaiting)();

    expect(app(Ota::class)->prompt())->toMatchArray(['available' => true, 'prompted' => true]);

    expect($this->bridge->callsTo('Dialog.Alert')[0]['params'])->toMatchArray([
        'title' => 'Update available',
        'buttons' => ['Later', 'Update'],
        'id' => Ota::PROMPT_ID,
        'event' => UpdatePromptAnswered::class,
    ]);
});

it('checks with the shell baseline so an older release is never offered', function () {
    ($this->releaseWaiting)();

    app(Ota::class)->prompt();

    expect($this->bridge->callsTo('Ota.Check')[0]['params'])
        ->toMatchArray(['shell_built_at' => '2026-09-28T20:00:00+00:00']);
});

it('asks nothing when there is no release waiting', function () {
    $this->bridge->respondTo('Ota.Check', ['available' => false, 'upToDate' => true]);

    app(Ota::class)->prompt();

    expect($this->bridge->callsTo('Dialog.Alert'))->toBeEmpty();
});

it('asks nothing about a release older than the installed app', function () {
    ($this->releaseWaiting)('2026-09-28T19:00:00+00:00');

    expect(app(Ota::class)->prompt())->toMatchArray(['available' => false])
        ->and($this->bridge->callsTo('Dialog.Alert'))->toBeEmpty();
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

it('waits for the first page before asking, then asks once per launch', function () {
    // PHP boots before the app is on screen, and a native alert raised then is
    // dropped, so nothing happens until a page has been served.
    ($this->releaseWaiting)();

    app(Ota::class)->onLaunch();

    expect($this->bridge->callsTo('Ota.Check'))->toBeEmpty();

    ($this->firstPage)();
    ($this->firstPage)();

    expect($this->bridge->callsTo('Ota.Check'))->toHaveCount(1)
        ->and($this->bridge->callsTo('Dialog.Alert'))->toHaveCount(1);
});

it('never asks in manual mode', function () {
    config(['nativephp-ota.mode' => 'manual']);
    ($this->releaseWaiting)();

    app(Ota::class)->onLaunch();
    ($this->firstPage)();

    expect($this->bridge->callsTo('Ota.Check'))->toBeEmpty()
        ->and($this->bridge->callsTo('Dialog.Alert'))->toBeEmpty();
});
