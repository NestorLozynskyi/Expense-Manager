# Expense Manager

Web application for tracking personal income and expenses (transactions, categories,
monthly budgets, statistics). **Current state: technical skeleton only** — no product
features or product UI yet.

## Stack

| Layer | Choice |
| --- | --- |
| Language / framework | PHP 8.5, Symfony 8.1, server-side rendering with Twig |
| Front end | HTMX, Alpine.js, Chart.js, Tailwind CSS v4 |
| Asset pipeline | Symfony AssetMapper + importmap (no Node build) and `symfonycasts/tailwind-bundle` (standalone Tailwind CLI) |
| Data | SQLite, Doctrine ORM, Doctrine Migrations |
| Auth | Symfony Security (entity user provider, `auto` password hasher) |
| Web server | FrankenPHP (Caddy + PHP in one process) in Docker |
| Tests | PHPUnit (unit / integration / functional), Playwright (e2e) |

Node.js is only needed on the host to run Playwright; it is not part of the app build.

## Run with Docker

```bash
docker compose up -d --build --wait     # build, start, wait for the healthcheck
open http://127.0.0.1:8089/             # redirects to the technical demo page /tech/demo
docker compose logs -f app
docker compose down                     # stop (data is kept)
docker compose down -v                  # stop and delete the database volume
```

* Port: `127.0.0.1:8089` (override with `APP_PORT=9000 docker compose up -d`).
* On every start the container applies pending Doctrine migrations
  (`doctrine:migrations:migrate --all-or-nothing`) before serving requests.
* The SQLite file lives in the named volume `app_data` (`/app/var/data/app.db`).
* `APP_SECRET` is never committed. Pass it as an environment variable, or leave it empty:
  the container then generates a random secret once and stores it in the data volume.
* Healthcheck endpoint: `GET /healthz`.

## Development tooling

All PHP commands run in the `tools` container (PHP 8.5 + Composer, source bind-mounted);
no local PHP is required.

```bash
docker compose build tools
docker compose run --rm tools composer install
docker compose run --rm tools php bin/console debug:router
docker compose run --rm tools php bin/console tailwind:build --watch
docker compose run --rm tools php bin/console make:migration    # after entity changes
```

## Tests

```bash
# PHPUnit: unit + integration + functional. Uses a separate SQLite DB (var/data/test.db)
# that tests/bootstrap.php recreates from the migrations on every run.
docker compose run --rm -e APP_ENV=test tools php bin/phpunit

# Playwright e2e against the running Docker app (start it first: docker compose up -d --wait)
npm install --include=dev          # --include=dev matters if NODE_ENV=production is set
npx playwright install chromium
npx playwright test                # screenshots -> artifacts/screenshots/, report -> playwright-report/
```

`E2E_BASE_URL` overrides the target URL for Playwright.

## Project structure

```
assets/                 JS entrypoint (app.js), chart helper, Tailwind input CSS
config/                 Symfony configuration (security.yaml, doctrine.yaml, ...)
docker/                 entrypoint.sh (secret + migrations), php.ini
migrations/             versioned Doctrine migrations
src/Controller/Tech/    technical controllers: /healthz, protected check, stack demo
src/Entity/             User (+ TEMPORARY TechDemoPing for the demo page)
src/Repository/         Doctrine repositories
src/Demo/               TEMPORARY demo-page helper
templates/              Twig templates (base layout, tech_demo/)
tests/Unit|Integration|Functional   PHPUnit tests; tests/Support helpers
tests/e2e/              Playwright specs
Dockerfile, compose.yaml
```

## Security baseline

* `App\Entity\User`: unique, normalised (lower-cased) email; password stored only as a hash.
* Everything under `/app` requires `ROLE_USER` (`access_control`). Technical check route:
  `GET /app/tech/secure-check` → 401 for anonymous visitors, JSON for signed-in users.
* Login / registration / logout are intentionally not implemented yet — they will be built
  with the approved UI design. Tests authenticate programmatically (`loginUser()`).

## Temporary technical parts (remove when product screens arrive)

* `/tech/demo` page, `TechDemoController`, `TechDemoPing` entity + `tech_demo_ping` table,
  `src/Demo/ChartDataBuilder`, `templates/tech_demo/`, `tests/e2e/tech-demo.spec.js`.
* `/` currently redirects to `/tech/demo`.
