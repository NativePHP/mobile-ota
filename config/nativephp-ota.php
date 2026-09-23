<?php

return [
    // prompt: yes/no on launch. silent: download/apply with no dialog. manual: only Ota::check() / a button.
    'mode' => env('NATIVEPHP_OTA_MODE', 'manual'),

    'project_uuid' => env('NATIVEPHP_OTA_PROJECT_UUID'),

    // Which channel to follow. An Arc is the environment the shell was built
    // for, so it arrives as APP_ENV — the builder owns that value and a project
    // cannot set it for itself. An app built before Arcs says "local", which is
    // no channel at all; Ota::identity() falls back for those.
    'arc' => env('APP_ENV', 'production'),

    // Which shells a release is meant for. Describes the installed shell, so it
    // comes from the build that produced it — ota.json once an update has been
    // applied, and this value before that.

    'shell_fingerprint' => env('NATIVEPHP_OTA_SHELL_FINGERPRINT'),

    // Written into a payload by the builder, so it is absent in a bundled app
    // and present in one running an update.
    'release_uuid' => env('NATIVEPHP_OTA_RELEASE_UUID'),

    // What the shell already contains. Restored from the shell's own metadata
    // after an update, so it describes the binary rather than the payload.
    'shell_built_at' => env('NATIVEPHP_OTA_SHELL_BUILT_AT'),

    'fingerprint_algorithm' => env('NATIVEPHP_OTA_FINGERPRINT_ALGORITHM', 1),

    // Bifrost-hosted delivery is Hela and up. Point this at your own URL for BYO bucket.
    'endpoint' => env('NATIVEPHP_OTA_ENDPOINT'),

    'token' => env('NATIVEPHP_OTA_TOKEN'),
];
