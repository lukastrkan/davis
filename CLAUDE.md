# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Davis: CalDAV/CardDAV/WebDAV server built on `sabre/dav`, wrapped in Symfony 7 with a Bootstrap admin dashboard and a JSON API. PHP 8.2+.

This repo is a fork of `tchapi/davis` (remote `upstream`). Upstream releases are merged in by tag (`git fetch upstream --tags && git merge vX.Y.Z`); keep local changes small and isolated so those merges stay easy (see "Fork-specific changes" below).

## Commands

```bash
composer install
php -S localhost:8000 -t public                 # local dev server
bin/console doctrine:migrations:migrate         # apply schema migrations
bin/console lint:container                      # quick check that DI wiring compiles
bin/console davis:mail:test you@example.org     # exercise the mailer
bin/console translation:extract en --force --domain=messages+intl-icu   # after adding/changing translations

PHP_CS_FIXER_IGNORE_ENV=True vendor/bin/php-cs-fixer fix   # lint/format (@Symfony rules); CI runs this

vendor/bin/phpunit                                          # full suite
vendor/bin/phpunit tests/Functional/DavTest.php             # one file
vendor/bin/phpunit --filter testSomething                   # one test
```

Tests need MariaDB/MySQL: `tests/bootstrap.php` creates the DB, runs `doctrine:schema:update --force` and loads fixtures before the suite, using `DATABASE_URL` from `.env.test` (`davis:davis@127.0.0.1:3306/davis_test`). For pure-PHP tests extending `PHPUnit\Framework\TestCase` (no kernel/DB) you can skip that: `vendor/bin/phpunit --bootstrap vendor/autoload.php path/to/Test.php`.

CI (`.github/workflows/ci.yml`) runs php-cs-fixer, PHPUnit on PHP 8.2–8.5 against MariaDB, migrations on MySQL, PostgreSQL and SQLite, and hadolint on the Dockerfiles.

## Architecture

**Two layers over the same tables.** The DAV server (sabre) and the admin UI (Doctrine) share one database. Doctrine entities in `src/Entity` map sabre's own tables (`calendars`, `calendarinstances`, `calendarobjects`, `principals`, `cards`, ...), and migrations in `migrations/` own the schema. Sabre never goes through Doctrine: `DAVController` grabs the native PDO from the Doctrine connection and hands it to sabre's PDO backends. Anything the admin UI writes must stay consistent with what sabre expects (sync tokens, change tables, principal URIs), and migrations must work on MySQL, PostgreSQL and SQLite (they branch on the platform).

**`src/Controller/DAVController.php` is the DAV server.** Its constructor builds the whole sabre `Server` on every request: picks the auth backend (`AUTH_METHOD` = Basic/IMAP/LDAP, `src/Services/*Auth.php`), builds the node tree (principals, CalDAV, CardDAV, WebDAV dirs, depending on the `*_ENABLED` env flags), and registers plugins. The `dav()` action (routes `/dav/{path}` and the Baïkal-compatible `/dav.php/{path}`) runs sabre with `CapturingSapi`, which captures sabre's output so it can be returned as a Symfony response. Exceptions from sabre are logged through the listener in `initExceptionListener()`.

**Customising sabre behaviour** is done by subclassing sabre classes and swapping them in `DAVController`, rather than patching vendor:
- `src/Plugins/`: `PublicAwareDAVACLPlugin` (ACL + public calendars), `DavisIMipPlugin` (invitation emails, templates in `templates/mails/scheduling.*.twig`), `BirthdayCalendarPlugin` (+ `Services/BirthdayService`), `DavisTemporaryFileFilterPlugin`.
- `src/CalDAV/`: namespaces mirror sabre's (`App\CalDAV\Backend\PDO` extends `Sabre\CalDAV\Backend\PDO`, `App\CalDAV\Schedule\Plugin` extends `Sabre\CalDAV\Schedule\Plugin`, etc.).

**Admin UI and API.** `src/Controller/Admin/*` (dashboard, users, calendars, address books, diagnostics) behind a form login whose single admin comes from `ADMIN_LOGIN`/`ADMIN_PASSWORD` env (`Security/AdminUserProvider`), not from the DAV users table. `src/Controller/Api/ApiController.php` is a stateless `/api/v1` API authenticated by `API_KEY` header (`Security/ApiKeyAuthenticator`; docs in `docs/api/`). `config/packages/security.yaml` `access_control` lists the public paths first; any new route is admin-only unless added there.

**Configuration** is almost entirely env vars, bound to parameters/arguments in `config/services.yaml`. `.env` documents them; `docker/.env` is the Docker variant. Translations live in `translations/` (en/fr/de, ICU format).

**Docker**: `docker/Dockerfile` (PHP-FPM, pair with a web server), `docker/Dockerfile-standalone` (bundled Caddy), compose files for MariaDB/PostgreSQL/SQLite in `docker/`.

This fork's image is built for x86 (`linux/amd64`, also when building on Apple Silicon) and tagged `gitea.ltrk.dev/lukas/davis`:

```bash
docker buildx build --platform linux/amd64 -f docker/Dockerfile-standalone -t gitea.ltrk.dev/lukas/davis .
```

## Fork-specific changes

Keep track of these when merging upstream:
- Sentry error reporting (`sentry/sentry-symfony`, `config/packages/sentry.yaml`, monolog handler in `config/services.yaml` + `config/packages/prod/monolog.yaml`); DAV 4xx exceptions are logged as warnings so they stay out of Sentry.
- `App\CalDAV\Backend\PDO`: stores events whose RRULE yields no instances instead of failing with a 500; `getCalendarsForUser()` drops a `calendar-timezone` that isn't a VCALENDAR (sabre would throw ParseException on free-busy/calendar-query).
- `DAVController::dav()` also answers `/dav` without trailing slash (`dav-root` route, declared after `dav`).
- `App\CalDAV\Schedule\Plugin` + `Broker`: organizer alarms (VALARM) are stripped from iTIP messages, and an attendee's own alarms survive organizer updates.
- `PublicAwareDAVACLPlugin::principalSearch()` override (guards against empty results with `hideNodesFromListings`).
- iMIP email subjects and templates are in Czech (upstream tests in `tests/Functional/Plugins/ImipPluginTest.php` expect English).
- Both Dockerfiles pin `php:8.4.27-fpm-alpine` (security release for CVE-2026-109618/109619) and set `session.upload_progress.enabled = Off`; go back to `php:8.4-fpm-alpine` once upstream's base image is past 8.4.27.
- `docker/.env` holds real deployment credentials: do not commit it.
