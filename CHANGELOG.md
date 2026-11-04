# Changelog

## 0.2.0

Breaking change: the plugin no longer ships a JavaScript API for OTA.

Removed `resources/js/ota.js`. It exposed `check`, `download`, `apply`, `rollback` and
`getStatus` to the web view through the native bridge. Any app that imported the `ota` JS
object and called those methods will break. Move the calls into PHP and use the `Ota` facade
instead: `Ota::check()`, `Ota::downloadAndApply()`, `Ota::rollback()`, `Ota::status()`.

This is deliberate. An OTA update replaces the code the app runs, so a page in the web view
must not be able to start a download or apply one. NativePHP core now refuses any `Ota.*`
bridge call that comes from the web view, so driving OTA from a web view is intentionally no
longer possible. The `Ota` facade is unchanged and is now the only way to drive OTA.

Earlier 0.1.x releases are listed under
[GitHub Releases](https://github.com/NativePHP/mobile-ota/releases).
