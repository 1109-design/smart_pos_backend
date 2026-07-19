<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ZIMRA Environment
    |--------------------------------------------------------------------------
    |
    | Selects which ZimraConfiguration row (and therefore which API URLs /
    | devices) is treated as current. This is data-level configuration.
    |
    */

    'environment' => env('ZIMRA_ENVIRONMENT', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Fiscalisation Safety Guard
    |--------------------------------------------------------------------------
    |
    | Production databases are routinely cloned to local machines for
    | development. A cloned database carries the real ZIMRA device
    | certificates, private keys, TINs, API URLs and the ZimraConfiguration
    | `environment` row — so NOTHING inside the database can tell you whether
    | you are running on the live server or a developer laptop.
    |
    | The guard therefore keys off runtime/machine signals that travel with the
    | server, not with the data dump:
    |   1. A hard master switch (ZIMRA_FISCALISATION_ENABLED).
    |   2. An APP_ENV allowlist — local clones run APP_ENV=local and are blocked.
    |   3. A host/IP allowlist — even if a developer copies the production .env,
    |      their machine hostname / IP will not match the live server.
    |
    | RECOMMENDED PRODUCTION SETUP (.env on the live server only):
    |   ZIMRA_FISCALISATION_ALLOWED_HOSTS="prod-host-name,10.0.0.5"
    |
    */

    'fiscalisation' => [

        // Master kill switch. Set to false anywhere to force-block every
        // state-changing ZIMRA call regardless of environment or host.
        'enabled' => env('ZIMRA_FISCALISATION_ENABLED', true),

        // APP_ENV values permitted to send live ZIMRA requests. 'testing' is
        // included so the automated test suite (APP_ENV=testing) is not blocked;
        // the host allowlist below is the real backstop for production.
        'allowed_environments' => array_filter(array_map(
            'trim',
            explode(',', (string) env('ZIMRA_FISCALISATION_ALLOWED_ENVIRONMENTS', 'production,testing'))
        )),

        // Hostnames and/or server IP addresses allowed to fiscalise. When empty
        // the check is skipped and the APP_ENV allowlist alone governs. Set this
        // on the production server for defence-in-depth against copied .env files.
        'allowed_hosts' => array_filter(array_map(
            'trim',
            explode(',', (string) env('ZIMRA_FISCALISATION_ALLOWED_HOSTS', ''))
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fiscalisation Failure Alerts
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of email addresses that receive an alert whenever a
    | sale or invoice fails (or is deferred) to fiscalise with ZIMRA. Intended
    | for development and verification — leave empty to disable.
    |
    */

    'failure_alert_emails' => array_filter(array_map(
        'trim',
        explode(',', (string) env('ZIMRA_FISCALISATION_ALERT_EMAILS', ''))
    )),

];
