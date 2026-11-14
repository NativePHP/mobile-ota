<?php

/**
 * Silent mode: once per launch, check, download and queue a waiting release
 * in the background, with nothing on screen and without holding up the boot.
 */

use Illuminate\Support\Facades\Event;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Nativephp\MobileOta\Events\UpdateApplied;
use Nativephp\MobileOta\Events\UpdateAvailable;
use Nativephp\MobileOta\Events\UpdateDownloaded;
use Nativephp\MobileOta\Events\UpdateFailed;
use Nativephp\MobileOta\Ota;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (! method_exists(FakeBridge::class, 'macro')) {
        $this->markTestSkipped('This core\'s FakeBridge does not support macros.');
    }

    $this->bridge = Native::fakeBridge();

    config([
        'nativephp-ota.mode' => 'silent',
        'nativephp-ota.endpoint' => 'https://bifrost.nativephp.com',
        'nativephp-ota.project_uuid' => 'project-uuid',
        'nativephp-ota.arc' => 'production',
        'nativephp-ota.shell_fingerprint' => str_repeat('a', 64),
        'nativephp-ota.shell_built_at' => '2026-09-28T20:00:00+00:00',
    ]);

    (new ReflectionProperty(Ota::class, 'updatingSilently'))->setValue(null, false);
});

it('hands the whole update to the native side and returns without touching the network', function () {
    // Even with a release waiting, PHP must not check or download it: on
    // launch that would hold the first screen up for as long as it takes.
    $this->bridge->withOtaUpdate('new-release');
    $this->bridge->respondTo('Ota.Silent', ['scheduled' => true]);

    Event::fake();

    app(Ota::class)->onLaunch();

    expect($this->bridge->callsTo('Ota.Check'))->toBeEmpty()
        ->and($this->bridge->callsTo('Ota.Download'))->toBeEmpty()
        ->and($this->bridge->callsTo('Ota.Apply'))->toBeEmpty()
        ->and($this->bridge->callsTo('Ota.Silent')[0]['params'])->toMatchArray([
            'endpoint' => 'https://bifrost.nativephp.com',
            'project' => 'project-uuid',
            'arc' => 'production',
            'fingerprint' => str_repeat('a', 64),
            'shell_built_at' => '2026-09-28T20:00:00+00:00',
            'available_event' => UpdateAvailable::class,
            'downloaded_event' => UpdateDownloaded::class,
            'applied_event' => UpdateApplied::class,
            'failed_event' => UpdateFailed::class,
        ]);

    // How it went arrives later, from the native side.
    Event::assertNothingDispatched();
});

it('says whether the native side took it on', function () {
    $this->bridge->respondTo('Ota.Silent', ['scheduled' => true]);

    expect(app(Ota::class)->updateSilently())->toBe(['scheduled' => true]);
});

it('starts once per launch', function () {
    app(Ota::class)->onLaunch();
    app(Ota::class)->onLaunch();

    expect($this->bridge->callsTo('Ota.Silent'))->toHaveCount(1);
});

it('never starts in manual or prompt mode', function (string $mode) {
    config(['nativephp-ota.mode' => $mode]);

    app(Ota::class)->onLaunch();

    expect($this->bridge->callsTo('Ota.Silent'))->toBeEmpty();
})->with(['manual', 'prompt']);

it('declares the native silent update it relies on', function () {
    $manifest = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true);
    $silent = collect($manifest['bridge_functions'])->firstWhere('name', 'Ota.Silent');

    expect($silent)->toMatchArray([
        'android' => 'com.nativephp.plugins.mobile_ota.OtaFunctions.Silent',
        'ios' => 'OtaFunctions.Silent',
    ])
        ->and(file_get_contents(dirname(__DIR__).'/resources/ios/OtaFunctions.swift'))->toContain('class Silent: BridgeFunction')
        ->and(file_get_contents(dirname(__DIR__).'/resources/android/OtaFunctions.kt'))->toContain('class Silent(private val activity: FragmentActivity)');
});

/**
 * Where each platform keeps the pieces the source tests below look for. Each
 * row is wrapped so Pest hands it over whole rather than as named arguments.
 */
$platforms = [
    'iOS' => [[
        'file' => 'ios/OtaFunctions.swift',
        'class' => 'class Silent: BridgeFunction',
        'scheduled' => '"scheduled": true',
        'background' => 'DispatchQueue.global(qos: .utility).async',
        'work' => ['static func updateSilently(', 'static func failureReason('],
        'offered' => ['static func downloadOffered(', 'static func updateSilently('],
        'download' => 'download(parameters: [',
    ]],
    'Android' => [[
        'file' => 'android/OtaFunctions.kt',
        'class' => 'class Silent(',
        'scheduled' => '"scheduled" to true',
        'background' => 'Thread {',
        'work' => ['fun updateSilently(', 'fun whenInForeground('],
        'offered' => ['fun downloadOffered(', 'fun failureReason('],
        'download' => 'Download(context).execute(downloadParameters)',
    ]],
];

/** The source from one marker up to the next, both of which must be there. */
function sourceBetween(string $source, string $from, string $to): string
{
    $start = strpos($source, $from);
    $end = $start === false ? false : strpos($source, $to, $start + strlen($from));

    expect($start)->not->toBeFalse("{$from} is missing")
        ->and($end)->not->toBeFalse("{$to} is missing after {$from}");

    return substr($source, $start, $end - $start);
}

it('works in the background natively, once per process', function (array $platform) {
    // PHP boots more than once per launch, so the native side keeps its own
    // flag, and hands the work to another thread before it returns.
    $source = file_get_contents(dirname(__DIR__).'/resources/'.$platform['file']);
    $silent = sourceBetween($source, $platform['class'], $platform['scheduled']);

    expect($silent)->toContain('claimSilent()')
        ->and($silent)->toContain($platform['background'])
        ->and($silent)->toContain('updateSilently(');
})->with($platforms);

it('downloads natively with the same safety checks as everything else', function (array $platform) {
    // One download path: silent mode goes through the code Ota.Download and
    // the prompt use, so the signature, checksum, size and shell-age checks
    // all apply, and a release already queued is not fetched again.
    $source = file_get_contents(dirname(__DIR__).'/resources/'.$platform['file']);
    $work = sourceBetween($source, ...$platform['work']);
    $offered = sourceBetween($source, ...$platform['offered']);

    expect($work)->toContain('pendingHolds(')
        ->and($work)->toContain('downloadOffered(')
        ->and($offered)->toContain('predatesShell(')
        ->and($offered)->toContain($platform['download']);

    foreach (['available_event', 'downloaded_event', 'applied_event', 'failed_event'] as $event) {
        expect($work)->toContain('"'.$event.'"');
    }
})->with($platforms);
