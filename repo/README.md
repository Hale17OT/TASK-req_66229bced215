# Studio Operations & Finance Console — fullstack

**Project type: fullstack** — ThinkPHP 6.1 REST API (PHP 8.1) + Layui 2.x web UI
+ MySQL 8.0, packaged as Docker services. Offline-capable fitness-studio
administration, budget control, reimbursement, settlement, and audit system.

---

## Quick start

Everything runs inside Docker. No local PHP / Node / npm / composer install
required — the image builds them.

```bash
docker compose up
```

The compose file ships `APP_ENV=development` plus placeholder secrets so a
fresh `docker compose up` works out of the box. On first boot the
`migrate` container creates the schema and seeds demo data (users, roles,
permissions, locations, departments). Wait until it prints `DONE` before
opening the UI.

**Access URL:** `http://localhost:8080` (port `8080` → `web` container's nginx)

### Before a production run

For a real deployment, copy `.env.example` → `.env`, set `APP_ENV=production`,
and fill in fresh secrets:

```bash
cp .env.example .env
printf 'APP_KEY=%s\nENCRYPTION_KEY=%s\n' \
  "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" >> .env
```

The startup guard in `app/service/security/FieldCipher.php` refuses to
boot an `APP_ENV=production|staging|live` container that still carries
the placeholder values, so a missed override fails loudly instead of
silently shipping weak keys.

---

## Demo credentials (all five roles)

Every demo user has the **same initial password** and is force-rotated on
first login. Change them before any real deployment.

| username     | email (none — local auth)       | password            | role           |
|--------------|----------------------------------|---------------------|----------------|
| `admin`      | `admin@local`                    | `Admin!Pass#2026`   | Administrator  |
| `frontdesk`  | `frontdesk@local`                | `Admin!Pass#2026`   | FrontDesk      |
| `coach`      | `coach@local`                    | `Admin!Pass#2026`   | Coach          |
| `finance`    | `finance@local`                  | `Admin!Pass#2026`   | Finance        |
| `operations` | `operations@local`               | `Admin!Pass#2026`   | Operations     |

> Authentication is local username/password only (spec §6 forbids external
> IdPs); the "email" column is shown for convenience only and maps to the same
> local account — it is not required for sign-in.

---

## Verify the API (curl)

Copy-paste these into a terminal while the stack is up. Each shows the
expected `code:0` / `message:"ok"` envelope.

### 1. Health probe (unauth)

```bash
curl -sS http://localhost:8080/healthz
```

Expected:

```json
{"status":"ok","ts":"2026-04-18T05:00:00+00:00"}
```

### 2. Sign in

```bash
curl -sS -c /tmp/cj -X POST http://localhost:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"Admin!Pass#2026"}'
```

Expected: HTTP 200 with

```json
{"code":0,"message":"ok","data":{"id":1,"username":"admin","roles":["Administrator"], ... },"meta":{...}}
```

A `STUDIOSESSID` and `studio_csrf` cookie are set in `/tmp/cj`.

### 3. Read the current user

```bash
curl -sS -b /tmp/cj http://localhost:8080/api/v1/auth/me
```

Expected: `{"code":0,"message":"ok","data":{"id":1,"username":"admin", ... }}`

### 4. List users (admin)

```bash
curl -sS -b /tmp/cj "http://localhost:8080/api/v1/admin/users?size=10"
```

Expected: `{"code":0,"message":"ok","data":{"total":5,"per_page":10, ... }}`

### 5. Create a budget category (needs CSRF + Idempotency headers)

```bash
CSRF=$(grep studio_csrf /tmp/cj | awk '{print $7}')
curl -sS -b /tmp/cj \
  -H "X-CSRF-Token: $CSRF" \
  -H "Idempotency-Key: cat-$(date +%s)" \
  -H 'Content-Type: application/json' \
  -X POST http://localhost:8080/api/v1/budget/categories \
  -d '{"name":"Facility Supplies"}'
```

Expected: `{"code":0,"message":"ok","data":{"id":1,"name":"Facility Supplies",...}}`

### 6. Audit search (Administrator only)

```bash
curl -sS -b /tmp/cj "http://localhost:8080/api/v1/audit?size=5"
```

Expected: `{"code":0,"message":"ok","data":{"total":N,"data":[{...}]}}` where
each row shows the `action`, `actor_username`, `occurred_at`, and the
`row_hash` field of the append-only hash chain.

### Negative check: unauthenticated `/me` is refused

```bash
curl -sS http://localhost:8080/api/v1/auth/me
```

Expected: `{"code":40100,"message":"Authentication required", ... }` with HTTP 401.

---

## Verify the UI (browser smoke flow)

After `docker-compose up` reports ready:

1. Open **http://localhost:8080** — browser redirects to `/pages/login.html`.
2. Sign in with `admin` / `Admin!Pass#2026`. The shell loads; sidebar shows
   every section (Users, Roles & permissions, Budgets, Reimbursements, …).
3. Click **Users** — a table lists the 5 seeded accounts. Click **Reset pwd**
   on `frontdesk`; a dialog shows a fresh temp password.
4. Click **Budget categories** in the sidebar, then **Add** with
   name `Supplies` — the new row appears in the list.
5. Click **Budget allocations**, pick the category, period `2026-04-01` to
   `2026-04-30`, cap `25000.00` → row is created.
6. Click **Reimbursements**, **Create draft** with amount `125.00` and a
   receipt number → upload any PDF → **Submit**. Status flips to
   `submitted` in the list.
7. Click **Audit search** — verify an `auth.login.success`, at least one
   `reimbursement.submitted`, and the row count increases as you act.
8. Click your username (top right) → **Sign out** → bounced back to login.

Any step that fails is a regression — open an issue with the browser console
and the nginx log (`docker compose logs web`).

---

## Run the tests

Tests hit real HTTP routes through the full ThinkPHP middleware pipeline
against a separate `studio_console_test` database. **No mocks are used** for
transport, middleware, controllers, services, or repositories — the only
substitution is that requests are constructed programmatically instead of
arriving via nginx + FPM.

```bash
# run everything (unit + api + integration). The script also ensures the
# test database exists and is migrated + seeded.
./scripts/run_tests.sh

# narrow by suite or filter
./scripts/run_tests.sh --testsuite=api
./scripts/run_tests.sh --testsuite=unit
./scripts/run_tests.sh --filter=ReimbursementApiTest
```

**Expected success output (counts grow as tests are added — final assertion
is exit code `0` and a single trailing `OK (...)` line):**

```
PHPUnit 9.6.34 by Sebastian Bergmann and contributors.

...............................................................  63 / 201 ( 31%)
............................................................... 126 / 201 ( 62%)
............................................................... 189 / 201 ( 94%)
............                                                    201 / 201 (100%)

Time: 05:20, Memory: 180.00 MB

OK (201 tests, 833 assertions)
```

Success criteria for the suite:

- **Exit code `0`** from `./scripts/run_tests.sh`.
- **A single trailing `OK (<n> tests, <m> assertions)` line** with no `F`, `E`,
  `R`, or `S` markers — `<n>` should be `≥ 201` after the audit-3 patch series.
- **Every endpoint in `route/api.php` is exercised** by at least one
  `tests/api/*ApiTest.php` test method that performs a real HTTP dispatch.

### Troubleshooting

| Symptom                                            | Fix                                                          |
|----------------------------------------------------|--------------------------------------------------------------|
| `Connection refused` on first run                  | Wait for `studio-db` to be `(healthy)`; re-run              |
| `Too many connections`                             | `docker compose restart db` (pool cap is 500; restart frees it) |
| Tests fail with stale schema after a migration     | `docker compose exec app php think migrate:rollback` then rerun `./scripts/run_tests.sh` |

---

## Project structure

```
repo/
├─ app/                         ThinkPHP backend
│  ├─ controller/api/v1/        REST controllers (one per resource)
│  ├─ middleware/               auth, RBAC, CSRF, idempotency, audit
│  ├─ service/                  domain services (workflow, money, audit, ...)
│  ├─ model/                    Eloquent-style models
│  └─ job/                      scheduled-jobs worker
├─ config/                      framework config (session, db, middleware, ...)
├─ route/api.php                75 REST endpoints (versioned /api/v1)
├─ public/                      web root (nginx-served)
│  ├─ pages/*.html              Layui admin pages
│  └─ static/                   layui/, css/, js/
├─ tests/
│  ├─ api/                      real-HTTP endpoint tests (170 tests, growing)
│  ├─ unit/                     pure-unit domain tests (19)
│  └─ integration/              DB-backed integration stubs (2)
├─ database/migrations/         phinx migrations (one per phase)
├─ scripts/
│  ├─ run_tests.sh              Dockerized test entry point
│  ├─ backup.sh  restore.sh     MySQL + attachments snapshot
│  └─ download-layui.sh         build-time Layui vendor fetch
├─ docker/                      Dockerfile + nginx + mysql + supervisord
├─ storage/                     attachments + exports (not in image)
└─ docker-compose.yml
```

---

## Security & operational notes

- Passwords hashed with Argon2id; rotation every 90 days; history of 5
- Lockout after 5 failed logins in 15 min (30 min cooldown)
- CSRF token enforced on every unsafe write endpoint
- Idempotency-Key required on every POST/PUT/DELETE that mutates state
- Audit log is append-only (DB trigger + hash chain)
- Sensitive fields masked in responses unless the caller has `sensitive.unmask`
