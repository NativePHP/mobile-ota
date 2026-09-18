<?php

return [
    // prompt: yes/no on launch. silent: download/apply with no dialog. manual: only Ota::check() / a button.
    'mode' => env('NATIVEPHP_OTA_MODE', 'manual'),

    'project_uuid' => env('NATIVEPHP_OTA_PROJECT_UUID'),

    // Which shells a release is meant for, and which channel to follow. Both
    // describe the installed shell, so they come from the build that produced
    // it — ota.json once an update has been applied, and these values before
    // that.
    'arc' => env('NATIVEPHP_OTA_ARC', 'staging'),

    'shell_fingerprint' => env('NATIVEPHP_OTA_SHELL_FINGERPRINT'),

    // Written into a payload by the builder, so it is absent in a bundled app
    // and present in one running an update.
    'release_uuid' => env('NATIVEPHP_OTA_RELEASE_UUID'),

    'fingerprint_algorithm' => env('NATIVEPHP_OTA_FINGERPRINT_ALGORITHM', 1),

    // Bifrost-hosted delivery is Hela and up. Point this at your own URL for BYO bucket.
    'endpoint' => env('NATIVEPHP_OTA_ENDPOINT'),

    'token' => env('NATIVEPHP_OTA_TOKEN'),
];
