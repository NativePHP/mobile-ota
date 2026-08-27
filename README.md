# nativephp/mobile-ota

Free NativePHP Mobile plugin for on-device OTA updates.

The Composer package is free. **Bifrost-hosted delivery is Hela and up.** Bring-your-own endpoint is env-only. Rollback is included. Attestation is not in v1.

## Install (this PR demo)

Already path-linked from `Herd/prs/demo` as `../plugins/mobile-ota`.

In other apps:

```bash
composer require nativephp/mobile-ota
php artisan native:plugin:register nativephp/mobile-ota
```

## Config

```
NATIVEPHP_OTA_MODE=manual   # prompt | silent | manual
NATIVEPHP_OTA_PROJECT_UUID=
NATIVEPHP_OTA_ENDPOINT=
NATIVEPHP_OTA_TOKEN=
```

`NATIVEPHP_OTA_TOKEN` is optional. If set, the native check sends `Authorization: Bearer {token}`. Bifrost's public check does not require it.

## Check API

`Ota::check()` asks the native plugin to GET:

```
GET {endpoint}/api/apps/{uuid}/ota?version={localVersion}
Accept: application/json
```

Example: `https://bifrost.nativephp.com/api/apps/{uuid}/ota?version=0`

The server JSON looks like:

```json
{
  "upToDate": false,
  "current_version": "3.0.13.0.3",
  "download_url": "https://…/laravel_bundle.zip?…"
}
```

Native returns that shape to PHP, plus aliases so existing callers keep working:

- `available` — `upToDate === false` and `download_url` is non-empty (server is source of truth)
- `version` — same as `current_version` (string, not int)
- `url` — same as `download_url`

Non-2xx responses or missing JSON yield `available: false` with a `reason`, not a crash.

Before the HTTP call, iOS (`NWPathMonitor`) and Android (`ConnectivityManager`) check that the device is online. Offline returns `available: false` with `reason: offline` and never hits Bifrost. This plugin does not depend on `nativephp/mobile-network`.


## PHP

```php
use Nativephp\MobileOta\Facades\Ota;

Ota::currentVersion();
Ota::check();
Ota::downloadAndApply();
Ota::rollback();
```

Native downloads the zip to the core pending path (not unzipped into the running Laravel tree):

- iOS: `{Documents}/updates/pending.zip`
- Android: `{appStorageDir}/updates/pending.zip`

Core extracts that zip on the next boot. `Ota::downloadAndApply()` / `Ota::install()` queue for next boot and return `queued` / `applyOnNextBoot` / `restartRequired`. They do not reboot the app.

Rollback keeps a plugin-owned `previous.zip` (last pending zip, copied aside before overwrite) and re-drops it as `pending.zip` for the next boot.

## Testing

The plugin extends the NativePHP testing suite with OTA-specific helpers, so app tests can fake and assert updates without knowing any bridge internals:

```php
use Native\Mobile\Testing\Native;

it('queues an OTA when the user confirms', function () {
    Native::fakeBridge()->withOtaUpdate('3.0.13.0.3', 'https://example.com/laravel_bundle.zip');

    Native::test(Home::class)
        ->tap('Check OTA')
        ->assertOtaChecked()
        ->tap('Download & queue')
        ->assertOtaDownloaded('https://example.com/laravel_bundle.zip')
        ->assertOtaQueued();
});

it('does not download when already up to date', function () {
    Native::fakeBridge()->withOtaUpToDate();

    Native::test(Home::class)
        ->tap('Check OTA')
        ->assertOtaChecked()
        ->assertNothingOtaDownloaded();
});

it('rolls back to the previous payload', function () {
    Native::fakeBridge();

    Native::test(Home::class)
        ->tap('Rollback')
        ->assertOtaRolledBack();
});
```

### Helpers

- `withOtaUpdate(string $version = '3.0.13.0.3', string $url = 'https://example.com/laravel_bundle.zip')` — script an available check. `$url` is what `Ota.Download` receives.
- `withOtaUpToDate(string $version = '1.0.0')` — script a check with nothing to download.
- `withOtaOffline()` — script `available: false, reason: offline`.
- `withOtaStatus(int|string $version = '1.0.0', bool $pending = false, bool $queued = false, bool $hasPrevious = false)` — script `Ota.GetStatus`.
- `assertOtaChecked()` — assert a check ran.
- `assertOtaDownloaded(?string $url = null)` — assert a download ran, or exactly `$url` when given.
- `assertOtaQueued()` — assert the zip was queued for core to extract on next boot.
- `assertOtaRolledBack()` — assert rollback re-dropped `previous.zip`.
- `assertNothingOtaDownloaded()` — assert no download ran.

The helpers are available on `Native::fakeBridge()` and chain directly off `Native::test(...)`. They register automatically while running tests (requires a core with a macroable FakeBridge; on older cores they simply don't register).
