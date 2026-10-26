# All's Well for Laravel and PHP

[All's Well](https://alls-well.io) monitors uptime, SSL certificates, cron jobs and servers, and alerts you when something goes wrong.

This package connects a Laravel app, or any other PHP app, to All's Well as a **site**. Every hour the app reports its PHP, framework and database versions, every Composer package it runs, and checks on its configuration, queues and scheduler. All's Well alerts you when the app stops reporting, and on the Pro and Business plans it checks the packages for known vulnerabilities. See [pricing](https://alls-well.io/pricing) for what each plan includes.

- [Requirements](#requirements)
- [Laravel quick start](#laravel-quick-start)
- [Many apps with one team key](#many-apps-with-one-team-key)
- [Other PHP apps](#other-php-apps)
- [What it sends, and what it never sends](#what-it-sends-and-what-it-never-sends)
- [Checks](#checks)
- [Commands](#commands)
- [Configuration reference](#configuration-reference)
- [It stays out of the way](#it-stays-out-of-the-way)
- [Copied and moved apps](#copied-and-moved-apps)
- [Troubleshooting](#troubleshooting)
- [Updating and removing](#updating-and-removing)
- [Support](#support)

## Requirements

- PHP 7.3 or later, with `ext-curl` and `ext-json`
- Composer 2.1 or later
- For the Laravel integration, Laravel 8 to 13. Any other PHP app reports through the command line.

## Laravel quick start

1. In All's Well, go to Sites → Add site, choose Laravel, and copy the site's token.
2. Install the package:

   ```sh
   composer require agilepixel/alls-well-php
   ```

3. Add the token to `.env`, with the site's public address unless `APP_URL` already is it:

   ```dotenv
   ALLS_WELL_TOKEN=your-site-token
   ALLS_WELL_SITE_URL=https://shop.example.com
   ```

   Many apps keep `APP_URL=http://localhost` in production, because their pages work regardless. The package won't report from a local or private address, so set `ALLS_WELL_SITE_URL` whenever `APP_URL` isn't the address people visit.

4. Run the installer. It publishes `config/alls_well.php`, creates the package's `alls_well_state` table, connects and sends the first report:

   ```sh
   php artisan alls-well:install
   ```

5. Reports go out every hour from Laravel's scheduler, so the scheduler must run. If it doesn't yet, add Laravel's cron line on the server:

   ```cron
   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
   ```

6. Check how it's set up. This sends nothing:

   ```sh
   php artisan alls-well:status
   ```

## Many apps with one team key

To connect a fleet without adding each app by hand, use your team's enrolment key instead of a site token. Issue one in All's Well under Sites → Connect many sites.

```dotenv
ALLS_WELL_KEY=amk_your-team-key
ALLS_WELL_SITE_URL=https://shop.example.com
```

```sh
php artisan alls-well:install
```

Each app enrols as a site of its own, under its own address. It keeps the site token it gets in its database, in the `alls_well_state` table that `alls-well:install` creates (`php artisan migrate` creates it too). With the key, that table is required: without it the app has nowhere to keep its token, so it doesn't enrol, and `alls-well:status` says why.

### Apps without a database

Set `ALLS_WELL_STORE=cache` to keep the connection in the app's cache instead. The cache must outlive each run, so the `array` and `null` drivers won't do. Clearing the cache (`php artisan cache:clear`) then loses some of it:

- **With a site token,** it forgets when the app last reported, any back-off after failures, and any pause, including a disconnect made with `alls-well:disconnect`. Reporting carries on with the token, and the next scheduled run reports at once.
- **With the key,** it also loses the site token the key enrolled. The app then enrols again, and All's Well refuses (`409 site_exists`) while the site is still reporting. So the site goes Down first; then All's Well gives the app a new token for the same site and tells your team. Keep the key on the database where you can.

Without `ALLS_WELL_STORE`, a token-mode app keeps its connection in the cache only until the `alls_well_state` table exists.

### Apps on several servers

The report is scheduled with Laravel's `onOneServer()`, so only one server sends it each hour. That only holds with a cache that supports locks and that every server shares, such as Redis, Memcached, DynamoDB or the database. Run `php artisan alls-well:connect` once by hand on one server, so the servers don't each try to enrol on their first scheduled run.

## Other PHP apps

Symfony, Slim, CodeIgniter and hand-rolled apps report through the package's command, run hourly from cron.

1. Install the package:

   ```sh
   composer require agilepixel/alls-well-php
   ```

2. Add the settings to the `.env` file in the project root, the directory with `composer.json`:

   ```dotenv
   ALLS_WELL_TOKEN=your-site-token
   ALLS_WELL_SITE_URL=https://shop.example.com
   ALLS_WELL_STATE_PATH=alls-well-state.json
   ```

   The package reads `.env`, then `.env.local`, in the project root itself, so cron sees the settings without your app's bootstrap. It reads only the `ALLS_WELL_*` settings and `APP_ENV`, and variables set in the environment win over both files. `ALLS_WELL_SITE_URL` is required: the command doesn't read `APP_URL`.

   `ALLS_WELL_STATE_PATH` names the file where the package remembers failures (and backs off), pauses and a disconnect. A relative path is read from the project root. It's recommended with a site token, and required with the key, because that file keeps the site token the key enrolled. The package replaces the file through a temporary one beside it, so its directory must be writable by the user cron runs as, and the file is readable by that user only. Keep `.env` and the state file outside the web root: in an app whose project root is its web root, give an absolute path elsewhere.

3. Check that it connects, as the user cron runs as. This sends a first report and says how it went:

   ```sh
   cd /path/to/app && vendor/bin/alls-well connect
   ```

4. Report every hour from cron, at any minute you like:

   ```cron
   17 * * * * cd /path/to/app && vendor/bin/alls-well report
   ```

   Cron emails whatever a job prints, so `report` prints nothing unless something needs fixing. Add `-v` to see every outcome. Run in a terminal, it always says how it went.

The command exits with `0` when it worked (or no report was due), `1` when it didn't, and `2` when it was used wrongly. Inside a Laravel app it stops and points to the artisan commands, so an app never has two connections.

### The config file

For settings `.env` can't hold, add `alls-well.php` to the project root (or name another file with `--config=PATH`). It returns an array, and the environment variables above win over its values:

```php
<?php

use AgilePixel\AllsWell\Checks\CheckResult;

return [
    'site_name' => 'Shop',
    'pdo' => function () {
        $db = require __DIR__.'/config/database.php';

        return new PDO($db['dsn'], $db['user'], $db['password']);
    },
    'checks' => [
        'app.uploads_writable' => function () {
            return is_writable(__DIR__.'/uploads')
                ? CheckResult::pass('Uploads folder is writable', 'high')
                : CheckResult::fail('Uploads folder is writable', 'high');
        },
    ],
    'administrators' => function () {
        return [
            ['id' => 1, 'login' => 'jane@example.com', 'registered_at' => '2024-01-31'],
        ];
    },
];
```

It takes these keys: `token`, `key`, `origin`, `site_url`, `site_name`, `environment`, `enabled` (`true` or `false`), `timeout` (seconds, 1 to 60), `pdo` (a `PDO`, or a function returning one, for the database type and version), `checks`, `administrators`, and `logger` (a function given a one-line message when something goes wrong).

The command reads nothing else from `.env`, and doesn't load your app, so read your own settings in this file the way your app does. A `pdo` function that fails only leaves the database version out of the report.

### The PHP API

An app with its own bootstrap can build the reporter itself and call it from its own scheduler. `AllsWell::reporter()` takes the config file's settings, and reads nothing from the environment:

```php
use AgilePixel\AllsWell\AllsWell;
use AgilePixel\AllsWell\State\FileStateStore;

$reporter = AllsWell::reporter([
    'token' => $siteToken,
    'site_url' => 'https://shop.example.com',
    'state' => new FileStateStore('/var/lib/shop/alls-well-state.json'),
    'pdo' => $pdo,
]);

$result = $reporter->report();

if ($result->needsAttention()) {
    error_log($result->message());
}
```

`report()` sends a report once one is due. The reporter also has `connect()`, `disconnect()` and `status()`. Each returns a `Result` with `isSuccess()`, `needsAttention()` and `message()`, except `status()`, which returns an array. None of them throws.

## What it sends, and what it never sends

A report carries:

- the site's address, name and environment name;
- the versions of PHP, the framework and the database server;
- the name and version of every Composer package installed for production;
- each check's result: pass, warn, fail or info, a label and a short value, such as a count or the mail transport's name;
- administrators' IDs, logins and registration dates, only when you opt in. A login that is an email address is masked (`jane@example.com` is sent as `j***@example.com`); any other login is sent as it is.

It never sends other `.env` values, passwords or keys, request data, file paths, or anything about your users beyond the administrators you opt in to report. When something fails, the package logs the exception's class, never its message, since that can carry paths or values.

## Checks

### Built-in checks

| Key | What it checks |
|---|---|
| `laravel.debug` | Debug mode is off. It fails in production. |
| `laravel.environment` | The environment's name. |
| `laravel.app_key` | `APP_KEY` is set. |
| `laravel.https` | `APP_URL` uses https. |
| `laravel.secure_cookies` | Session cookies are marked secure. |
| `laravel.mail` | The default mailer sends mail, rather than logging it. |
| `laravel.config_cached` | The configuration is cached. |
| `laravel.routes_cached` | The routes are cached. |
| `laravel.events_cached` | The events are cached. |
| `laravel.failed_jobs` | How many queued jobs have failed. |
| `laravel.queue_size` | How many jobs wait on the default queue, against `queue_size_threshold`. |
| `laravel.horizon` | Horizon is running, when Horizon is installed. |
| `laravel.schedule_failures` | How many scheduled tasks failed in the last 24 hours. |
| `laravel.migrations` | Migrations are up to date. |
| `php.dev_packages` | No Composer dev packages are installed. This one runs in every app, Laravel or not. |

The debug, mail, https, secure-cookie and cache checks, and `php.dev_packages`, only warn or fail in production (an environment named `production`, `prod` or `live`); elsewhere they report for information. The others apply everywhere.

The migrations check counts the package's own migration as pending until `php artisan migrate` (or `alls-well:install`) has run.

### Your own checks

A check returns a `CheckResult`: `pass`, `warn`, `fail` or `info`, with a label, a severity (`critical`, `high`, `medium`, `low` or `info`) and an optional value. Its key is lowercase letters, digits, dots, dashes and underscores, such as `app.stripe`, and can't start with `server.` or `addon.`. A check that throws is reported as failed, and never stops the report.

In Laravel, list check classes in `config/alls_well.php`. Each implements `AgilePixel\AllsWell\Checks\Check`, or is invokable, and comes from the container. `null` turns a `laravel.*` check off:

```php
'checks' => [
    'app.stripe' => App\Checks\StripeCheck::class,
    'laravel.horizon' => null,
],
```

```php
namespace App\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Checks\CheckResult;

class StripeCheck implements Check
{
    public function run()
    {
        return config('services.stripe.secret')
            ? CheckResult::pass('Stripe is configured', 'high')
            : CheckResult::fail('Stripe is configured', 'high');
    }
}
```

Closures don't belong in the config file, because `php artisan config:cache` can't store them. Register them from a service provider's `boot()` instead:

```php
use AgilePixel\AllsWell\Checks\CheckResult;
use AgilePixel\AllsWell\Laravel\AllsWell;

AllsWell::check('app.backups', function () {
    return CheckResult::pass('Backups ran today', 'high');
});
```

Other PHP apps return their checks from `alls-well.php`, as functions or `Check` objects.

### spatie/laravel-health

When [spatie/laravel-health](https://github.com/spatie/laravel-health) is installed with a result store, its latest stored results are reported as checks, keyed `health.<name>`: ok passes, warning warns, failed and crashed fail, and anything else is information. The package doesn't run those checks again, so schedule them as that package describes.

### Administrators

Reporting administrators is off until you opt in. All's Well then lists them on the site page, and can tell you when a new one appears. In Laravel, register a resolver from a service provider's `boot()`:

```php
use AgilePixel\AllsWell\Laravel\AllsWell;
use App\Models\User;

AllsWell::administrators(function () {
    return User::where('is_admin', true)->get()->map(function ($user) {
        return ['id' => $user->id, 'login' => $user->email, 'registered_at' => $user->created_at];
    })->all();
});
```

Or name an invokable class as `administrators` in `config/alls_well.php`. Other PHP apps return an `administrators` function from `alls-well.php`. Logins that are email addresses are masked before they leave the app; other logins, such as usernames, are sent as they are.

## Commands

### Laravel

| Command | What it does |
|---|---|
| `php artisan alls-well:install` | Publishes the config file, creates the `alls_well_state` table (asking first), connects and sends the first report. |
| `php artisan alls-well:connect` | Connects now, even while backing off after failures, and sends a report. It ends a disconnect; with the key, it also enrols a deleted site again, as a new site. |
| `php artisan alls-well:report` | Sends the report when one is due; `--manual` sends it whether or not one is due, unless the package is backing off after a failure. The scheduler runs it every hour. It prints nothing unless something needs fixing; `-v` shows every outcome. |
| `php artisan alls-well:status` | Shows the settings, the connection, the last result and the schedule. It sends nothing. |
| `php artisan alls-well:disconnect` | Tells All's Well the site is going, and stops reporting until `alls-well:connect`. It asks first, so scripts need `--force`. |

### Other PHP apps

| Command | What it does |
|---|---|
| `vendor/bin/alls-well report` | Sends the report when one is due; `--manual` sends it whether or not one is due, unless the package is backing off after a failure. `-v` shows every outcome. |
| `vendor/bin/alls-well connect` | Connects now, even while backing off after failures, and sends a report. It ends a disconnect; with the key, it also enrols a deleted site again, as a new site. |
| `vendor/bin/alls-well status` | Shows the settings and the state. It sends nothing. |
| `vendor/bin/alls-well disconnect` | Tells All's Well the site is going, and stops reporting until `connect`. It needs `ALLS_WELL_STATE_PATH`. |

Each takes `--config=PATH` to read another config file. `--help` lists the commands and options, and `--version` shows the package's version.

## Configuration reference

In Laravel, `php artisan alls-well:install` publishes `config/alls_well.php`; `php artisan vendor:publish --tag=alls-well-config` does too.

| Variable | Config key | Default | What it sets |
|---|---|---|---|
| `ALLS_WELL_TOKEN` | `token` | none | The site token from All's Well. |
| `ALLS_WELL_KEY` | `key` | none | Your team's enrolment key (`amk_…`), which enrols the app as a new site. A site token wins over it. |
| `ALLS_WELL_URL` | `url` (`origin` in `alls-well.php`) | `https://alls-well.io` | The All's Well address. Set it only when the site's Connect steps in All's Well include it. |
| `ALLS_WELL_SITE_URL` | `site_url` | `APP_URL` in Laravel; required elsewhere | The address the site is served from. It must be public. |
| `ALLS_WELL_SITE_NAME` | `site_name` | `APP_NAME` in Laravel, unless it's `Laravel`; otherwise the site's host | The name the site is reported under. |
| `ALLS_WELL_ENVIRONMENT` | `environment` | the app's environment (`APP_ENV`) | The environment name reported. |
| `ALLS_WELL_ENABLED` | `enabled` | on | `false`, `0`, `off` or `no` turns reporting off without removing the package. |
| `ALLS_WELL_STORE` | `store` | `auto` | Laravel only: where the connection is kept. `auto` uses the `alls_well_state` table (or, with a site token, the cache until the table exists); `database` or `cache` forces one. |
| `ALLS_WELL_STATE_PATH` | none | none | Other PHP apps only: the file where the connection and state are kept. |
| none | `skip_environments` | `['local', 'testing']` | Laravel only: environments that never report or schedule anything. |
| none | `schedule` | `true` | Laravel only: `false` leaves scheduling `alls-well:report` to you. |
| none | `timeout` | `15` | Seconds to wait for All's Well, from 1 to 60. |
| none | `queue_size_threshold` | `1000` | Laravel only: queued jobs above this count warn. |
| none | `checks` | `[]` | Your own checks. In Laravel, `null` turns a `laravel.*` check off. |
| none | `administrators` | `null` | The administrators to report: an invokable class in Laravel, a function in `alls-well.php`. |

`ALLS_WELL_TOKEN` and `ALLS_WELL_KEY` each accept either value: the package tells a site token from a team key by its shape.

## It stays out of the way

- **Nothing runs on a web request.** The service provider merges its config and registers its migration; its commands and schedule load only in the console.
- **It never throws.** Whatever fails, All's Well being unreachable included, comes back as a message and an exit code.
- **The report runs in the background,** without overlapping, so a slow All's Well or a hung cache can't hold up your other scheduled tasks. Calls to All's Well time out after `timeout` seconds. The package's own cache and database calls, Redis included, have no timeout of their own, so a hung Redis can stall the report itself; running in the background keeps that from stalling anything else.
- **Local and testing environments don't report,** and schedule nothing, unless you change `skip_environments`. Without a token or key, nothing is scheduled either.
- **It doesn't break your deploys.** `composer install`, `config:cache` and `migrate` work before the package is set up.

## Copied and moved apps

- **A copy with the key,** such as a staging app cloned from production, enrols as a new site under its own address. The connection it copied belongs to the address it was made for, so it never reports as the original.
- **A copy with the site token** is refused by All's Well (`site_moved`), and pauses until you fix it. Give the copy a site of its own, and its token.
- **To move an app to a new domain,** change the site's address in All's Well, deploy the app at its new address, then run `connect`. With the key, also set `ALLS_WELL_TOKEN` to the site's token (on the site page in All's Well): otherwise the app at its new address enrols as a new site.

## Troubleshooting

Start with `php artisan alls-well:status` (or `vendor/bin/alls-well status`). It sends nothing, and lists anything to fix under `errors`.

- **`paused: site_moved`:** All's Well refused a report from this address, and `last_error` says why. If the app moved, change the site's address in All's Well. If it's a copy, give it its own site token. Then run `connect`.
- **`last_status: 409` with "This site is already connected and reporting":** an app with the key tried to enrol (`site_exists`) at an address whose site is still reporting, usually after its stored token was lost. Paste the site's token as `ALLS_WELL_TOKEN`, or delete the site in All's Well.
- **`paused: deleted`:** the site was deleted in All's Well, so the app stopped reporting. With the key, `connect` enrols it as a new site. With a site token, set `ALLS_WELL_TOKEN` to a new site's token, or remove the package.
- **"This site's address … isn't public"** (or `site_url_not_public` from All's Well): the address is localhost or a private network. Set `ALLS_WELL_SITE_URL` to the address the site is served from.
- **The site is Down in All's Well:** the app stopped reporting, often because the scheduler or the cron line stopped. Check `last_report_at`, and in Laravel that `schedule` says when it runs and that `php artisan schedule:run` runs every minute.
- **`config cached: yes` and no token found:** the configuration was cached before the token was added. Run `php artisan config:clear`, or `config:cache` again.

## Updating and removing

Update with Composer. The site page in All's Well says when a newer version exists.

```sh
composer update agilepixel/alls-well-php
```

To remove it, disconnect first, so All's Well knows the site is going, then remove the package and the `ALLS_WELL_*` settings:

```sh
php artisan alls-well:disconnect --force
composer remove agilepixel/alls-well-php
```

Other PHP apps run `vendor/bin/alls-well disconnect` and remove the cron line. The site stays in All's Well until you delete it there, and the `alls_well_state` table stays in a Laravel app's database until you drop it.

## Support

- Email [hello@alls-well.io](mailto:hello@alls-well.io).
- This repository is published with each release, so pull requests can't be merged here. Open an issue or email us instead.
- Report security problems by email to the same address, not in an issue.

## License

MIT. See [LICENSE](LICENSE).
