# Changelog

The notable changes in each release, newest first. Versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.1

- `php artisan alls-well:install` with `ALLS_WELL_STORE=cache` no longer tries to create the `alls_well_state` table. With the team key on an app without a database, it stopped there; now it connects.
- The `laravel.secure_cookies` check decides whether the site is served over https from its reported address (`ALLS_WELL_SITE_URL`, else `APP_URL`), so it also warns on an app whose `APP_URL` is `http://localhost`.
- The README corrects what happens when an app on the team key moves domain, when the cache store is needed, and what to remove before `composer remove`. It adds which check results alert, what `disconnect` does in All's Well, and the site limit.

## 1.0.0

The first release.

- Connects a Laravel 8 to 13 app to All's Well as a site:
  - an hourly report from Laravel's scheduler, run in the background;
  - the `alls-well:install`, `connect`, `report`, `status` and `disconnect` artisan commands;
  - built-in checks for configuration mistakes, queues, Horizon, failed scheduled tasks and pending migrations;
  - your own checks, from the config file or registered in code;
  - the latest stored results of spatie/laravel-health, reported as checks.
- Connects any other PHP 7.3+ app through `vendor/bin/alls-well`, run hourly from cron.
- Connects with a site token, or with your team's enrolment key, which adds each app as a site of its own.
- Protects copied and moved apps: a copy never reports as the site it was copied from.
