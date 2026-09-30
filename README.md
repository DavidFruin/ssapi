# Simple Social API

The PHP backend for Simple Social — split out of the [`simple-social`](https://github.com/DavidFruin/simple-social) repo on 2026-09-30 so the backend and frontend are independently cloneable.

`simple-social` still contains the original vanilla-JS frontend (and is what's actually deployed today), but the PHP application code now lives here as the source of truth going forward. See that repo's own notes for the eventual plan: [`ssreact`](https://github.com/DavidFruin/ssreact) (the React rewrite) is meant to become the frontend served alongside this backend.

## Structure

- `api.php`, `media.php` — the two HTTP entry points every client (web, CLI, TUI) talks to
- `auth.php` — JWT issuing/verification, shared by `api.php` and `media.php`
- `config.php` — reads runtime config/secrets from outside the repo (`private/.env`, see below) — no secrets are committed here
- `logging.php`, `schema.php`, `webpush.php`, `clean-notifications.php`, `migrate-posts.php` — supporting modules
- `src/{Auth,Comments,Follows,Media,Notifications,Posts,Users}/handlers.php` — per-domain action handlers, loaded via Composer's `files` autoload
- `tests/backend-tests/` — backend test scripts

## Configuration

This app reads its JWT secret and other runtime config from a `.env`-style file **outside** this repo (see `.env.example` for the shape). It also expects a SQLite database at a path outside the repo (see `config.php`/`schema.php`). Neither is committed here — see the deployment host's own setup for where those actually live.

## Deploying

Not yet automated (see the [`ssreact`](https://github.com/DavidFruin/ssreact) repo's war-table note for the current manual deploy process and the plan for GitHub Actions once that's greenlit).
