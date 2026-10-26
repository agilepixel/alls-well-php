<?php

return [

    // The site token from All's Well.
    'token' => env('ALLS_WELL_TOKEN'),

    // Your team's enrolment key (amk_...), used to enrol this site on its first report.
    'key' => env('ALLS_WELL_KEY'),

    // The All's Well address; leave empty for the default.
    'url' => env('ALLS_WELL_URL'),

    // The address the site is reported under; falls back to app.url.
    'site_url' => env('ALLS_WELL_SITE_URL'),

    // The name the site is reported under; falls back to app.name.
    'site_name' => env('ALLS_WELL_SITE_NAME'),

    // The environment name reported; falls back to app.env.
    'environment' => env('ALLS_WELL_ENVIRONMENT'),

    // A switch to turn reporting off without removing the package.
    'enabled' => env('ALLS_WELL_ENABLED', true),

    // Where the connection is kept: auto (the alls_well_state table, or the cache
    // until it is migrated), database or cache. Set cache if this app has no database.
    'store' => env('ALLS_WELL_STORE', 'auto'),

    // Environments that never report or schedule anything.
    'skip_environments' => ['local', 'testing'],

    // Whether the package adds its hourly report to the scheduler.
    'schedule' => true,

    // Seconds to wait for All's Well before giving up on a report.
    'timeout' => 15,

    // Queued jobs above this count are reported as a backlog.
    'queue_size_threshold' => 1000,

    // Your own checks, as 'key' => CheckClass::class; null turns a built-in off.
    'checks' => [],

    // Opt in to reporting administrators: an invokable class name returning
    // [['id' => ..., 'login' => ..., 'registered_at' => ...], ...]. Logins are sent masked; null keeps it off.
    'administrators' => null,

];
