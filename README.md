# NativePHP Mobile OTA

Over-the-air updates for [NativePHP Mobile](https://nativephp.com) apps. Send a new version of your app's PHP code,
views and assets straight to the phones it's installed on, without a store review.

This plugin is the part that runs in your app. It checks for an update, downloads it, and hands it to NativePHP to
apply the next time the app starts. Updates are built and published by [Bifrost](https://bifrost.nativephp.com). See
[OTA Updates](https://bifrost.nativephp.com/docs/mobile/builds/ota) in the Bifrost docs for sending them.

The plugin is free.

## What an update can change

An OTA update replaces your Laravel app: routes, controllers, Livewire components, Blade views, CSS, JavaScript and
images.

It can't change what's compiled into the app. Adding or updating a NativePHP plugin, updating `nativephp/mobile`, or
changing native settings in `config/nativephp.php` (app ID, permissions, deep links and so on) still needs a new store
build.

## Requirements

- `nativephp/mobile` 4.5.2 or later
- iOS and Android

## Installation

```shell
composer require nativephp/mobile-ota
php artisan native:plugin:register nativephp/mobile-ota
```

Then ship a new store build from Bifrost. Bifrost writes everything the app needs to find its updates into the build:
the project, the [Arc](https://bifrost.nativephp.com/docs/mobile/builds/arcs) it was built on, and a fingerprint of its
native setup. You don't need to set any of it yourself.

An app only receives updates published to its own Arc, for the same native setup it was built with. A Staging build
gets Staging updates, and a Production build gets Production updates.

## Choosing when to update

Set `NATIVEPHP_OTA_MODE` in your `.env`:

| Mode | What happens |
|---|---|
| `manual` (default) | Nothing happens on its own. Your app decides when to check, using the methods below. |
| `silent` | The app checks for updates and downloads them automatically. A downloaded update is applied the next time the app starts. |

## Checking and applying updates yourself

In `manual` mode, use the `Ota` facade, for example from a "Check for updates" button in your settings screen:

```php
use Nativephp\MobileOta\Facades\Ota;

$update = Ota::check();

if ($update['available']) {
    Ota::downloadAndApply();
}
```

`Ota::check()` returns an array. `available` is `true` when there's a newer update for this app. When the phone is
offline or the check fails, `available` is `false` and `reason` says why.

`Ota::downloadAndApply()` checks again, downloads the update, verifies its checksum and size, and queues it. It never
restarts your app. The update is applied the next time the app starts, so you might tell the user to restart to
finish updating.

### Rolling back

```php
Ota::rollback();
```

Puts the previously downloaded update back, to be applied the next time the app starts. It returns `false` if there's
nothing to roll back to.

### Status

```php
Ota::status();         // ['pending' => true, 'hasPrevious' => true, ...]
Ota::currentRelease(); // The release this app is running, or null if it's still on the version it shipped with
```

`pending` is `true` when an update is downloaded and waiting for the next start.

## Events

Each step dispatches a Laravel event you can listen for:

| Event | When |
|---|---|
| `Nativephp\MobileOta\Events\UpdateAvailable` | A check found an update. |
| `Nativephp\MobileOta\Events\UpdateDownloaded` | An update was downloaded. |
| `Nativephp\MobileOta\Events\UpdateApplied` | An update was queued for the next start. |
| `Nativephp\MobileOta\Events\RolledBack` | A rollback was queued for the next start. |
| `Nativephp\MobileOta\Events\UpdateFailed` | A check, download, apply or rollback failed. `$stage` says which, and `$message` says why. |

## Safety

- The app never installs an update older than the code it was built with, even if it's offered one.
- A download whose checksum or size doesn't match what Bifrost published is discarded.
- The phone checks it's online before asking for an update.

## Testing

The plugin adds OTA helpers to NativePHP's testing bridge, so your tests can fake updates:

```php
use Native\Mobile\Testing\Native;

it('queues an update when the user taps the button', function () {
    Native::fakeBridge()->withOtaUpdate();

    Native::test(Settings::class)
        ->tap('Check for updates')
        ->assertOtaChecked()
        ->assertOtaDownloaded()
        ->assertOtaQueued();
});

it('does nothing when the app is up to date', function () {
    Native::fakeBridge()->withOtaUpToDate();

    Native::test(Settings::class)
        ->tap('Check for updates')
        ->assertOtaChecked()
        ->assertNothingOtaDownloaded();
});
```

Scripting a check:

- `withOtaUpdate(string $version = '3.0.13.0.3', string $url = 'https://example.com/laravel_bundle.zip')`
- `withOtaUpToDate(string $version = '1.0.0')`
- `withOtaOffline()`
- `withOtaStatus(int|string $version = '1.0.0', bool $pending = false, bool $queued = false, bool $hasPrevious = false)`

Assertions:

- `assertOtaChecked()`
- `assertOtaDownloaded(?string $url = null)`
- `assertOtaQueued()`
- `assertOtaRolledBack()`
- `assertNothingOtaDownloaded()`

The helpers need a NativePHP core whose testing bridge is macroable. On older cores they aren't registered.

## License

MIT
