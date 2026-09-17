<?php

return [
    /*
     * The shared secret for the JSON endpoint. Paste the same value into the monitor's Token
     * field. Leave it unset and the endpoint is open to anyone who finds it — acceptable locally,
     * never in production, because the payload names every driver this app runs on.
     */
    'token' => env('HEARTBEAT_TOKEN'),

    /*
     * The machine endpoint: JSON, token-gated, and registered with **no middleware at all**.
     *
     * That is not an oversight. These apps keep sessions in the database, so a route inside the
     * `web` group boots a session out of the database it is being asked about — and answers 500
     * instead of "the database is gone" at the one moment the answer matters.
     */
    'api' => [
        'enabled' => env('HEARTBEAT_API', true),
        'path' => env('HEARTBEAT_API_PATH', 'health'),
        'middleware' => [],
    ],

    /*
     * The ops page for humans. Behind the session, because whoever reads it is signed in anyway
     * and a page listing drivers and release SHAs is not for the public. If the database is down
     * this route will fail with it — that is what `api` above is for.
     *
     * It lives under `health/` rather than at `/heartbeat` so that an app which already has a
     * heartbeat page of its own keeps it, untouched, and gains only the machine endpoint. Set
     * `HEARTBEAT_PAGE=false` in those apps and nothing here is registered at all.
     */
    'web' => [
        'enabled' => env('HEARTBEAT_PAGE', true),
        'path' => env('HEARTBEAT_PAGE_PATH', 'health/ops'),
        'middleware' => ['web', \GonbiDigital\Heartbeat\Http\EnsureHeartbeatAccess::class],

        /*
         * Whether being signed in is enough to read the page. The health token always is, which
         * is the only way in when the login system is itself part of what has broken.
         */
        'allow_authenticated' => true,

        /* Which guards count as signed in. Empty means every guard the app has configured. */
        'guards' => [],
    ],

    /* Each may be switched off where it cannot mean anything — an app with no queue, say. */
    'checks' => [
        'database' => true,
        'cache' => true,
        'storage' => true,
        'queue' => true,
    ],

    'queue' => [
        // Depth alone misses a worker that died on a quiet afternoon, so age is watched too.
        'pending_threshold' => 1000,
        'stale_after_seconds' => 900,
    ],

    'storage' => [
        // null follows `filesystems.default`. Name a disk to probe a specific one instead.
        'disk' => null,
    ],

    'deploy' => [
        'release_file' => 'RELEASE',
    ],
];
