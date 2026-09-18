<?php

return [
    // Bumped whenever a local Drift schema migration ships that the server
    // needs to be aware of (e.g. a table/column the sync payload shape
    // depends on). Returned to the client by SyncController::status() so a
    // device can eventually detect "the server expects a newer local
    // schema than I have" — informational only today, nothing enforces it
    // yet.
    'schema_version' => (int) env('SYNC_SCHEMA_VERSION', 1),

    // A device reporting an app_version below this is running a build the
    // server considers unsupported for sync (e.g. it predates a breaking
    // payload-shape change). Defaults to '0.0.0' so this is inert until a
    // real minimum is deliberately configured — SyncController::status()
    // returns it, but nothing currently blocks sync on it; that
    // enforcement is a deliberate follow-up, not silently added here.
    'minimum_supported_app_version' => env('SYNC_MIN_APP_VERSION', '0.0.0'),
];
