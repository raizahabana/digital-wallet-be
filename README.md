# Digital Wallet & Ledger Service

A headless REST API for a digital wallet: deposits, peer-to-peer transfers, a double-entry style ledger,
and queued CSV statement exports. Built for the Laravel technical capability assessment.

**Stack:** Laravel 13 · PHP 8.3 · MySQL 8 · Laravel Sanctum · database queue

The companion React client lives in `digital-wallet-fe`.

---

## Quick start (Docker, recommended)

Requires Docker with Compose. Nothing else needs to be installed.

```bash
docker compose up --build
```

This starts four containers:

| Service     | What it does                                                                 |
|-------------|------------------------------------------------------------------------------|
| `app`       | `php artisan serve` on <http://localhost:8000>. Runs `migrate --seed` on boot |
| `queue`     | `php artisan queue:work`: generates statements and sends transfer notifications |
| `scheduler` | `php artisan schedule:work`: prunes expired tokens, old idempotency keys, old statements |
| `mysql`     | MySQL 8, exposed on host port **3307**. Creates `digital_wallet` and `digital_wallet_test` |

Seeded accounts (each wallet's opening balance is backed by a ledger entry):

| Email                | Password   | Balance |
|----------------------|------------|---------|
| `test@example.com`   | `password` | 1000.00 USD |
| `second@example.com` | `password` | 250.00 USD  |

Try it:

```bash
# 1. Get a token
curl -s -X POST localhost:8000/api/v1/auth/token \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"test@example.com","password":"password"}'

# 2. Transfer (Idempotency-Key is required)
curl -s -X POST localhost:8000/api/v1/wallet/transfer \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer <token>" -H "Idempotency-Key: $(uuidgen)" \
  -d '{"recipient_email":"second@example.com","amount":"25.50"}'
```

## Local setup (without Docker)

Requires PHP 8.3+ (with `pdo_mysql`, `bcmath`), Composer, and a running MySQL 8 server.

```bash
composer install
cp .env.example .env
php artisan key:generate

# create the databases once
mysql -uroot -e "CREATE DATABASE digital_wallet; CREATE DATABASE digital_wallet_test;"

php artisan migrate --seed
php artisan serve          # API on http://localhost:8000
php artisan queue:work     # REQUIRED: statements and notifications are processed here
php artisan schedule:work  # optional: runs the daily clean-up jobs
```

Adjust `DB_HOST`, `DB_USERNAME` and `DB_PASSWORD` in `.env` to match your MySQL server.

> **Why MySQL and not SQLite?** The non-negative balance `CHECK` constraints and the
> `SELECT ... FOR UPDATE` row locks that prevent double-spending are not enforced by SQLite.
> Running on SQLite would silently hide the behaviour this project is meant to demonstrate.

If you start the queue worker *after* requesting a statement, the job simply waits in the `jobs` table
and is processed as soon as a worker is running.

## Running the tests

The suite runs against the `digital_wallet_test` MySQL database (forced in `phpunit.xml`), never your
development data.

```bash
# Docker
docker compose exec app php artisan test

# Local
php artisan test
```

If your MySQL volume was created before this project added the test database, create it manually:
`CREATE DATABASE digital_wallet_test;`

What is covered:

| Area | Tests |
|------|-------|
| Happy paths | token issue, wallet view, deposit, transfer, statement request, download |
| Validation | zero / negative / non-numeric / 3-decimal amounts → 422, bad emails, missing fields |
| Integrity | overdraft rejected, exact-balance transfer, rollback when the second ledger write fails, linked `reference_id` entries, cross-currency and self-transfer refused |
| Concurrency | `ConcurrentTransferTest` launches 6 separate OS processes (each with its own DB connection) that race to spend the same wallet; exactly 3 of 6 × 30.00 against 100.00 may succeed and the balance must end at 10.00 |
| Locking | queries are inspected to prove the row lock is taken **before** the balance is checked |
| Database constraints | negative balance, zero/negative ledger amount, unknown type, duplicate wallet, cascade delete, `[wallet_id, created_at]` index |
| Queue and events | `Queue::fake()` assertions for the statement job and the notification listener; events are discarded on rollback |
| Idempotency and throttling | replay, payload mismatch, failed attempts not burning the key, 5/min per-user limit |
| Clean-up | old idempotency keys and statements (plus their files) are pruned |
| Units | `TransferFunds` and `DepositFunds` Actions, DTO immutability |

Tests that need real row locks or `CHECK` constraints skip themselves if you point `phpunit.xml` at SQLite.

---

## API

Base URL `http://localhost:8000/api/v1`. Send `Accept: application/json`. Everything except
`POST /auth/token` and the signed download link requires `Authorization: Bearer <token>`.

| Method | URI | Purpose | Success |
|--------|-----|---------|---------|
| POST | `/auth/token` | Exchange email and password for a Sanctum bearer token (2 h expiry) | 200 |
| POST | `/auth/logout` | Revoke the token used for the request | 204 |
| GET | `/wallet` | Balance, currency and the latest 15 transactions (single eager-loaded query) | 200 |
| POST | `/wallet/deposit` | Deposit funds. Body: `{ "amount": "10.50" }` | 201 |
| POST | `/wallet/transfer` | Peer-to-peer transfer. Body: `{ "recipient_email": "...", "amount": "10.50" }`. **Header `Idempotency-Key` required** | 201 |
| POST | `/wallet/statements` | Queue a CSV export. Returns a status URI in the `Location` header and `data.status_url` | **202** |
| GET | `/wallet/statements/{id}` | Poll status: `queued` → `processing` → `completed` / `failed` | 200 |
| GET | `/wallet/statements/{id}/download` | Signed, 10-minute link returned in `download_url` once completed | 200 (CSV) |

Successful responses use a `{ "data": { ... } }` envelope. Examples:

```jsonc
// POST /auth/token
{ "data": { "token": "1|abc...", "token_type": "Bearer", "expires_at": "2026-11-10T12:00:00+00:00" } }

// POST /wallet/transfer
{ "data": { "reference_id": "9b2f...-uuid", "recipient_email": "second@example.com", "amount": "25.50" } }

// POST /wallet/statements  (202, Location: .../wallet/statements/{id})
{ "data": { "id": "...", "status": "queued", "status_url": "...", "download_url": null, "error": null } }
```

### Errors (RFC 7807)

Every error is `application/problem+json`:

```json
{ "type": "about:blank", "title": "Insufficient funds", "status": 422, "detail": "Insufficient wallet balance." }
```

Validation failures (422) add an `errors` object keyed by field. Unexpected 500s return a generic
message; the real exception goes to the log only, never to the client.

| Status | When |
|--------|------|
| 401 | missing, invalid or expired token; wrong credentials |
| 404 | unknown recipient, recipient without a wallet, someone else's statement |
| 409 | a request with the same `Idempotency-Key` is still in progress |
| 422 | invalid amount (≤ 0, non-numeric, more than 2 decimals), insufficient funds, self-transfer, currency mismatch, missing or reused idempotency key |
| 429 | more than 5 transfers per minute (per user) or 5 login attempts per minute (per email + IP) |

---

## Design decisions

**Race conditions.** Deposits and transfers run inside `DB::transaction()` and take a pessimistic lock
(`lockForUpdate`) on the wallet row(s) *before* the balance is read. A transfer locks both wallets in
ascending ID order, so two opposite transfers (A→B and B→A) can never deadlock. Balance changes use
`increment` / `decrement` (a single atomic `UPDATE`).

**Defence in depth.** Validation rejects bad amounts in the Form Request, the Action re-checks funds under
the lock, and the schema has `CHECK (balance >= 0)` and `CHECK (amount > 0)` as the last line of defence.

**Ledger.** Every balance change has a matching `transactions` row. A transfer writes `transfer_out` and
`transfer_in` entries sharing one `reference_id`, in the same transaction as the balance updates, so
either everything is stored or nothing is. Money is handled as strings and `decimal(15,2)`, never floats.

**Layers.** `Http/Requests` validate → controllers stay thin → `Actions/Wallet` own the business rules →
`DataTransferObjects` carry typed input and output → `Http/Resources` shape responses. Business failures
are domain exceptions (`InsufficientFundsException`, …) that extend `ProblemException` and are mapped to
RFC 7807 responses in one place (`bootstrap/app.php`).

**Events.** `TransferCompleted` implements `ShouldDispatchAfterCommit`. It is dispatched inside the
transfer transaction but only delivered after `COMMIT`, and discarded on rollback, so no one is notified
about money that did not move. The queued `SendTransferNotifications` listener sends a mail receipt to both
parties (`MAIL_MAILER=log` writes them to `storage/logs`).

**Statements.** `POST /wallet/statements` only creates a `statements` row and dispatches a `ShouldQueue`
job, so it answers immediately with 202. The worker streams the ledger to CSV in chunks of 500. Failures
mark the statement `failed` with a generic message. Downloads use short-lived signed URLs so a plain link works.

**Idempotency.** Transfers require an `Idempotency-Key`. The key is claimed first through a unique
`(user_id, key)` index (the database decides which of two simultaneous retries wins). A retry with the same
key and body replays the stored response (`Idempotency-Replayed: true`); the same key with a different body
is refused. Failed attempts release the key so the client can correct and retry.

**Clean-up.** `model:prune` runs daily: idempotency keys older than 24 h and statements (with their CSV
files) older than 7 days are deleted, and `sanctum:prune-expired` removes expired tokens.

## Project layout

```
app/
  Actions/Wallet/            TransferFunds, DepositFunds (business rules)
  DataTransferObjects/       TransferData, DepositData, TransferResult
  Events/ Listeners/ Notifications/
  Exceptions/                domain exceptions → RFC 7807
  Http/{Controllers,Requests,Resources,Middleware}
  Jobs/GenerateStatement.php
  Models/ Support/Problem.php
database/migrations/         schema, constraints, indexes
docker/ docker-compose.yml   reproducible environment
tests/{Feature,Unit,Support}
```

## Configuration

| Variable | Purpose |
|----------|---------|
| `DB_*` | MySQL connection (Docker overrides these for you) |
| `QUEUE_CONNECTION=database` | queue driver; the worker must be running |
| `MAIL_MAILER=log` | transfer receipts are written to `storage/logs/laravel.log` |
| `config/cors.php` | allows the React dev server at `http://localhost:5173` |
