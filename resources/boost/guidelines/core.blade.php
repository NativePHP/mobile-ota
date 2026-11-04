## nativephp/mobile-ota

Over-the-air PHP payload updates for a NativePHP Mobile app.

### OTA is driven from PHP only

There is no JavaScript or web API for OTA. A page running in the app's web view can't check
for, download, or apply an update. An update replaces the code the app runs, so the decision
to fetch and install one stays in your app's PHP. Drive it with the `Ota` facade.

### Installation

```bash
composer require nativephp/mobile-ota
php artisan native:plugin:register nativephp/mobile-ota
```

### PHP Usage

Use the `Ota` facade:

@verbatim
<code-snippet name="Checking for and applying an update" lang="php">
use Nativephp\MobileOta\Facades\Ota;

$update = Ota::check();

if ($update['available']) {
    // Downloads, verifies the checksum, and queues the update.
    // It is applied the next time the app starts.
    Ota::downloadAndApply();
}
</code-snippet>
@endverbatim

### Available Methods

- `Ota::check()`: Ask whether a newer release is waiting for this app
- `Ota::downloadAndApply()`: Download, verify and queue an update for the next launch
- `Ota::rollback()`: Put the previously downloaded update back for the next launch
- `Ota::status()`: Whether an update is downloaded and pending
- `Ota::currentRelease()`: The release this app is running, or `null` if it shipped as-is

### Events

Each step dispatches a Laravel event you can listen for:

@verbatim
<code-snippet name="Listening for OTA Events" lang="php">
use Illuminate\Support\Facades\Event;
use Nativephp\MobileOta\Events\UpdateApplied;

Event::listen(UpdateApplied::class, function (UpdateApplied $event) {
    // $event->version
});
</code-snippet>
@endverbatim

Available events: `UpdateAvailable`, `UpdateDownloaded`, `UpdateApplied`, `RolledBack`, `UpdateFailed`.
