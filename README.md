# Simple Social API

The PHP backend for Simple Social — split out of the [`simple-social`](https://github.com/DavidFruin/simple-social) repo on 2026-09-30 so the backend and frontend are independently cloneable.

`simple-social` still contains the original vanilla-JS frontend (and is what's actually deployed today), but the PHP application code now lives here as the source of truth going forward. See that repo's own notes for the eventual plan: [`ssreact`](https://github.com/DavidFruin/ssreact) (the React rewrite) is meant to become the frontend served alongside this backend.

## Structure

- `api.php`, `media.php` — the two HTTP entry points every client (web, CLI, TUI) talks to
- `auth.php` — JWT issuing/verification, shared by `api.php` and `media.php`
- `config.php` — reads runtime config/secrets from outside the repo (`private/.env`, see below) — no secrets are committed here
- `logging.php`, `schema.php`, `webpush.php` — supporting modules
- `src/{Auth,Comments,Follows,Media,Notifications,Posts,Users}/handlers.php` — per-domain action handlers, loaded via Composer's `files` autoload
- `deploy/` — everything that goes **into** a domain's `public_html/`, not into this repo's own deployed location: `root.htaccess` (the canonical web-root `.htaccess`), `public/api.php` and `public/media.php` (thin entry stubs that `require` this repo's own `api.php`/`media.php`), and `public/index.maintenance.php` (dormant until renamed to `index.php` on the server). See the war-table's `Inbox/deploy-layout-plan.md` for the full layout and why.

Tests live in [`sstests`](https://github.com/DavidFruin/sstests) (`backend/`), not here — kept out of this repo on purpose so test code never ships to the server.

## Configuration

This app reads its JWT secret and other runtime config from a `.env`-style file **outside** this repo (see `.env.example` for the shape). It also needs a MariaDB database, named in that `.env` (`DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `DB_SOCKET` or `DB_HOST`); `schema.php` builds the tables the first time. Neither is committed here — see the deployment host's own setup for where those actually live.

**From SQLite (before 2026-11-27 the database was `private/userdata.db`):** a site moves once, while it shows the maintenance page: make its MariaDB database and login, put them in its `.env`, deploy this code, then from the site's `ssapi/` folder run `php bin/sqlite-to-mariadb.php ../private/userdata.db`. It copies every table and checks every row (`CHECK OK`); `--check` repeats the check alone. The SQLite file must be at schema 12 (ssapi 3.0.0). Keep the file as the rollback. A site that still runs on SQLite (for example another site using this code for its accounts) must not get this version before it has moved.

## Deploying

Not yet automated. Each domain is laid out as three separate folders:

```
<domain>/
  private/        .env, logs/, tmp/, locks/   (unchanged; the data is in MariaDB)
  ssapi/          this repo's checkout (code only, never web-served)
  public_html/
    .htaccess     deploy/root.htaccess, deployed on purpose, never by an app deploy
    api.php       deploy/public/api.php  -> requires ../ssapi/api.php
    media.php     deploy/public/media.php -> requires ../ssapi/media.php
    app/          the frontend build (ssreact dist/, or the vanilla frontend until it's replaced)
    media/        user uploads
    downloads/    static downloads (APK etc.)
```

See the war-table's `Inbox/deploy-layout-plan.md` for the full rationale, the exact deploy commands, and the migration runbook for a host still on the old flat layout (everything directly in `public_html`).
