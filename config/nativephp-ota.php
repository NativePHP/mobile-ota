<?php

return [
    // prompt: yes/no on launch. silent: download/apply with no dialog. manual: only Ota::check() / a button.
    'mode' => env('NATIVEPHP_OTA_MODE', 'manual'),

    'project_uuid' => env('NATIVEPHP_OTA_PROJECT_UUID'),

    // Bifrost-hosted delivery is Hela and up. Point this at your own URL for BYO bucket.
    'endpoint' => env('NATIVEPHP_OTA_ENDPOINT'),

    'token' => env('NATIVEPHP_OTA_TOKEN'),
];
