# Changelog

The notable changes in each release, newest first. Versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
