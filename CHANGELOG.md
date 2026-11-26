# Changelog

The notable changes in each release, newest first. Versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.0

- A CMS plugin, or any host with screens of its own, can now be built on the package. The README's "Building on the package" lists what it can use:
  - connecting with a button, or with a token or key someone types, and reporting with the connection its state store keeps;
  - its own platform (`craft` is now one), versions, components, checks and wording.
- A copy of such a host's stored connection, at another address or in another environment, sends nothing, unless `ALLS_WELL_KEY` is set to enrol it as a site of its own.
- A Craft CMS site that reports through the command line now shows Craft CMS as its core, instead of Yii.
- Where the All's Well plugin for Craft CMS is installed, the package's command and Laravel integration step aside for it.
- Addresses ending in `.ddev.site` or `.lndo.site` (DDEV and Lando) count as local, as they do in All's Well. An app at one no longer reports.
- `status` shows the site's page in All's Well (`dashboard_url`), once the package knows it, and when the next report is due (`next_report_at`).

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
