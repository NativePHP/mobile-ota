<?php

/**
 * Events the native side sends back. On an EDGE screen core only passes a
 * native event to Laravel's dispatcher when the class carries its
 * BroadcastsGlobally marker, and rebuilds it from the payload by name.
 */

use Illuminate\Support\Facades\Event;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use Nativephp\MobileOta\Events\UpdateDownloaded;
use Nativephp\MobileOta\Events\UpdateFailed;
use Nativephp\MobileOta\Events\UpdatePromptAnswered;
use Tests\TestCase;

uses(TestCase::class);

$sent = [
    'the prompt answer' => [UpdatePromptAnswered::class, ['index' => 1, 'label' => 'Update', 'id' => 'nativephp-ota-update']],
    'a finished download' => [UpdateDownloaded::class, ['version' => 'new-release']],
    'a failed download' => [UpdateFailed::class, ['stage' => 'download', 'message' => 'checksum mismatch']],
];

it('reaches app listeners from an EDGE screen', function (string $class, array $payload) {
    if (! interface_exists(BroadcastsGlobally::class)
        || ! method_exists(NativeComponent::class, 'dispatchGloballyIfMarked')) {
        $this->markTestSkipped('This core does not route native events to global listeners.');
    }

    expect(is_subclass_of($class, BroadcastsGlobally::class))->toBeTrue();

    Event::fake([$class]);

    // Core's own EDGE path, as the event loop runs it for a native event.
    $component = (new ReflectionClass(new class extends NativeComponent {}))->newInstanceWithoutConstructor();
    (new ReflectionMethod(NativeComponent::class, 'dispatchGloballyIfMarked'))
        ->invoke($component, $class, $payload);

    Event::assertDispatchedTimes($class, 1);
    Event::assertDispatched($class, function ($event) use ($payload) {
        foreach ($payload as $key => $value) {
            if ($event->{$key} !== $value) {
                return false;
            }
        }

        return true;
    });
})->with($sent);

it('can still be built the way a webview screen builds it', function (string $class, array $payload) {
    // POST /_native/api/events does new $class(...$payload).
    expect(new $class(...$payload))->toBeInstanceOf($class);
})->with($sent);
