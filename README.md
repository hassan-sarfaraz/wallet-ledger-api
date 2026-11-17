# Wallet Ledger API

Laravel 13 / PHP 8.3 / MySQL 8.4. A stateless JSON API with an append-only ledger and transactional per-user balances.

## Run

Requires Docker Engine / Docker Desktop with Compose v2. No host PHP, Composer, Node or MySQL is needed to start the API.

```sh
docker compose up --build
```

The API listens on port **8080**. Startup waits for MySQL, runs migrations, caches configuration/routes and starts Apache with 128 PHP workers. A subsequent `docker compose up` also works. Data persists in the named MySQL volume. The seed is intentionally a separate command, as described in the assessment review procedure:

```sh
docker compose exec api php artisan ledger:seed --no-interaction
docker compose exec api php artisan ledger:reconcile --history --no-interaction
```

The default seed creates exactly **10,000,000 ledger rows across 100,000 users**. Ten users (`seed_0` through `seed_9`) each have **100,000 rows**; the remaining rows are spread across the other users. Credits of 100 alternate with debits of 40 per user, so every historical balance is nonnegative. It uses server-side set-based inserts and 50,000-row transactions rather than hydrating millions of PHP objects. Each batch appends entries and applies its signed deltas atomically. Run it offline against empty wallet/ledger tables; it refuses to delete or overwrite existing data. An interrupted seed leaves committed batches consistent but is not resumable; use a new disposable database to restart.

Compose sets `API_TOKEN` to `recruitment-test-token` by default, and accepts an environment override. Recreate the API container after changing it. Application code reads only configuration; it does not contain the token. The Compose database credentials and APP_KEY are local assessment defaults, not deployment secrets.

## API contract

Every request requires `Authorization: Bearer <API_TOKEN>`. Missing/invalid authentication returns 401 before routing, validation or database access. Token digests are compared using `hash_equals`. No authentication endpoint, cookies or session database are used.

| Method/path | JSON input | Success |
| --- | --- | --- |
| POST /credit | {"user_id":"u123","amount":10000} | 200 {"user_id":"u123","balance":10000} |
| POST /debit | {"user_id":"u123","amount":4000} | 200 {"user_id":"u123","balance":6000} |
| GET /balance/u123 | none | 200 {"user_id":"u123","balance":6000} |

Amounts are positive JSON integers from 1 through 1,000,000,000, in minor currency units. Decimal/exponent numbers, numeric strings, booleans, zero and negatives are rejected. User IDs are case-sensitive ASCII strings of 1?64 letters, digits, underscores or hyphens. Unknown body fields are ignored. Query-string fields cannot supply missing JSON fields.

Errors have exactly `{"error":"<code>","message":"<text>"}`.

| HTTP | Code | Condition |
| --- | --- | --- |
| 400 | invalid_request | Malformed/non-object JSON, missing/invalid field |
| 401 | unauthorized | Missing/wrong bearer token or scheme |
| 404 | user_not_found | Balance/debit for a user never credited |
| 409 | insufficient_funds | Debit would make the balance negative; nothing is written |

Credit creates a wallet on first use. Zero-balance wallets remain valid. A successful mutation returns the balance immediately following that operation, captured while its lock is held and returned only after commit.

Example (POSIX shell; set `API_TOKEN` first):

```sh
curl -s -X POST localhost:8080/credit \
  -H "Authorization: Bearer $API_TOKEN" -H "Content-Type: application/json" \
  -d '{"user_id":"u123","amount":10000}'
```

## Storage and concurrency

- `wallets`: case-sensitive ASCII `user_id VARCHAR(64)` primary key and signed `BIGINT balance`, with a nonnegative CHECK constraint.
- `ledger`: unsigned BIGINT auto-increment primary key, matching `user_id`, credit/debit enum, positive integer amount and database-generated microsecond timestamp. A foreign key prevents orphan entries and provides the `user_id` secondary index.
- BEFORE UPDATE and BEFORE DELETE triggers reject ledger mutations, including direct SQL. Administrative schema changes remain an operator responsibility.
- No redundant ledger index or timestamp index is added. The primary key supports sequential appends; the user index supports offline reconciliation/history work. API balance reads never query the ledger.

The ledger is the source of truth; `wallets.balance` is a synchronous, rebuildable projection, not an eventually consistent cache. Every credit/debit updates that row and inserts its ledger entry in one InnoDB transaction. Errors roll back both. Failed debits and unknown users write nothing.

Credit first uses `INSERT ... ON DUPLICATE KEY UPDATE` to safely establish and lock the wallet, including simultaneous first credits. It then locks/reads the wallet, changes its balance and appends the ledger entry. Debit starts with `SELECT ... FOR UPDATE`, checks funds under that lock, updates the balance and appends. No read/check/write sequence runs outside a transaction. The lock is held until commit.

The connection uses **READ COMMITTED**. Locking reads see current committed values after waiting, avoiding stale-balance decisions. The second debit of 60 against an initial 100 therefore sees 40 and returns 409. Only one wallet is locked per mutation; different users do not share a logical lock, and READ COMMITTED avoids missing-row gap locks. A hot user still serializes its own writes, as required.

The Apache runtime uses persistent PDO connections, bounded by its 128 workers, to avoid a new database handshake and TCP socket for every request. PHPUnit disables persistence so independent test connections remain independent. Each request still opens and commits its own transaction; connection reuse does not cache balances. Keep worker/connection limits aligned when scaling.

Laravel retries a whole transaction up to 100 times on recognized deadlocks/lock-wait conflicts. The callback has no external side effects. Re-execution cannot leave half a ledger write or apply a rolled-back increment twice. This is a finite retry budget, not a guarantee of availability during database outage or indefinite lock starvation.

Balance reads use a fresh indexed primary-key lookup on the primary database with no replicas or cache. A read beginning after a successful mutation sees that commit or a later commit. A read overlapping a mutation may correctly observe the prior committed value. Multiple API instances share the same database locks; no process-local mutex is used.

Alternatives considered:

- Summing the ledger per request becomes proportional to a user's history and fails the hot-user requirement.
- Redis/asynchronous projections introduce a second consistency boundary and complicate crash recovery and read-your-writes.
- Optimistic version/CAS updates require repeated read/modify/retry work under hot-user contention.
- A global application lock blocks unrelated users and does not naturally coordinate multiple instances.
- SERIALIZABLE adds unnecessary contention; REPEATABLE READ adds gap-lock/snapshot complexity. READ COMMITTED plus explicit row locks is sufficient here.
- Eloquent models add hydration/lifecycle work to these small scalar queries without improving this domain boundary. A focused service uses Laravel's query builder and bound SQL values.

## Tests

The PHPUnit suite uses **real MySQL**, never SQLite, in the isolated `wallet_ledger_test` database. Docker initializes that database and grants the assessment user access. Tests guard the database name before any migration/transaction setup and refuse the application database. PHPUnit explicitly sets its own environment and an independent configuration-cache path, so the API keeps its production configuration cache. Run:

```sh
docker compose exec -T api php vendor/bin/phpunit --colors=never
```

The direct PHPUnit command also avoids the Artisan wrapper attempting to read a `.env` file inside the environment-only container. Do not run tests and load measurements simultaneously. Tests cover the API, strict input boundaries, unknown users, case sensitivity, balances beyond 32-bit range, authentication with an unreachable database, immutable entries, injected failure before commit, deadlock retry, seed consistency and historical nonnegative balances. The retry and isolation tests use real transaction commits rather than a surrounding test transaction. A separate connection holds an uncommitted wallet update while the main connection credits another user and reads only the committed balance.

For black-box concurrency/load tests, use **Node 22+** on the host (no npm dependencies). Set `API_TOKEN` in your shell to the configured token:

```sh
node scripts/concurrency.mjs
node scripts/load.mjs
MODE=hot node scripts/load.mjs
docker compose exec api php artisan ledger:reconcile --no-interaction
```

PowerShell: `$env:API_TOKEN='...'`, `$env:MODE='hot'`, then `node scripts/load.mjs`. Clear MODE for the mixed run.

The concurrency script submits 1,000 credits to a new user using 100 concurrent clients by default, verifies every returned balance, repeats the two-debit race 20 times, mixes 1,000 credits/debits and checks sequential read-your-writes. Set `CONCURRENCY=1000` for the simultaneous 1,000-client burst. Its users have random prefixes and it can run repeatedly without resets. The HTTP agent bounds active connections to the requested concurrency; bursts use one-shot connections, while load measurements reuse connections.

For the full 1,000-client burst without Windows Docker Desktop port forwarding, use the optional test-client profile after starting the API:

```sh
docker compose run --rm -T -e CONCURRENCY=1000 -e OUTPUT=/results/docker-concurrency.json checks node concurrency.mjs
```

The checks profile uses a temporary Node 22 container and the same scripts on the Docker network. It does not start during normal `docker compose up`. On this Windows host, 1,000 simultaneous clients through the published port produced connection resets even though the same burst passed on the Docker network; the published port is separately tested with the assessment's 100 concurrent clients.

The load script warms 500 requests, then runs 10,000 measured requests with 100 concurrent clients: 30% credit, 30% debit, 40% reads. It records p50/p99 separately per endpoint, response statuses and failures, exits nonzero on unexpected errors and tests both many users and one hot user. Environment controls: `BASE_URL`, `REQUESTS`, `CONCURRENCY`, `USERS`, `MODE=hot`, `LABEL`, `OUTPUT`. OUTPUT writes the JSON report.

For 1M versus 10M comparisons, use two empty disposable databases with the same configuration and 100,000 users. Run `ledger:seed --rows=1000000` in the first and the default seed in the second, then run mixed/hot measurements sequentially against each. Reconcile after load; do not seed while measuring.

## Measured results

Local Windows x64 laptop: Intel i5-8365U, 8 logical CPUs, 7.75 GiB RAM; PHP 8.3.28, MySQL 8.4.7, Apache 2.4.65 with 128 threads, OPcache and configuration caching enabled, Xdebug disabled, persistent PDO enabled. These are native Windows measurements, not Docker benchmarks. Details and raw reports are in [results/](results/).

Each recorded load run warmed 500 requests and measured **3,000 requests at concurrency 100** (900 credits, 900 debits, 1,200 reads). The script defaults to 10,000 measured requests for longer reviewer runs. The two databases began with 1M and 10M rows; load/concurrency checks append additional rows. All four final runs returned 3,000 HTTP 200 responses and **zero failures**.

| Seed size | Mode | Endpoint | p50 (ms) | p99 (ms) |
| --- | --- | --- | ---: | ---: |
| 1M | mixed | credit | 366.879 | 455.074 |
| 1M | mixed | debit | 365.034 | 445.935 |
| 1M | mixed | balance | 356.327 | 430.136 |
| 1M | hot | credit | 461.051 | 717.884 |
| 1M | hot | debit | 464.45 | 725.475 |
| 1M | hot | balance | 440.992 | 694.429 |
| 10M | mixed | credit | 388.729 | 565.238 |
| 10M | mixed | debit | 390.361 | 551.99 |
| 10M | mixed | balance | 372.772 | 454.109 |
| 10M | hot | credit | 368.949 | 498.041 |
| 10M | hot | debit | 372.12 | 506.48 |
| 10M | hot | balance | 359.357 | 474.739 |

The 10M mixed run has roughly 6?7% higher p50 than the 1M mixed run, rather than growing with history length. The p99 and hot-user figures vary with laptop scheduling/cache state; a single short run is not a statistical performance guarantee. Reviewer hardware and Docker results will differ.

Seed times: **1M = 50.66 seconds**, **10M = 482.27 seconds (8m 02s)**. The 10M seed ran while some development checks were active, so this is an observed wall time rather than an isolated best-case seed benchmark.

**47 PHPUnit tests / 214 assertions passed.** The black-box concurrency suite passed 1,000 simultaneous first credits with distinct immediate balances, 20 double-debit races, 1,000 mixed writes, and 20 read-your-writes rounds against the 10M dataset. SQL reconciliation and the full 10M historical running-balance check passed; final post-load reconciliation outputs are saved alongside the reports.


### Docker verification

Docker Desktop 4.99.0 (Linux engine 29.9.3 / WSL 3.0.5), PHP 8.3.36, Laravel 13.42.0, Apache 2.4.68 and MySQL 8.4.13. The full 10M/100k-user seed finished in **306.78 seconds (5m 07s)**. The final image passes **47 tests / 214 assertions** using the isolated test database.

The following measurements use the published host port with 100 concurrent clients, 500 warm-up requests and **10,000 measured requests per run**. Both final runs returned 10,000 HTTP 200 responses and zero failures. They use the bounded Node HTTP agent and Apache keep-alive timeout of 5 seconds; the earlier native comparison used the original fetch driver, so compare results within each environment.

| Mode | Endpoint | p50 (ms) | p99 (ms) |
| --- | --- | ---: | ---: |
| mixed | credit | 260.445 | 871.054 |
| mixed | debit | 255.043 | 880.813 |
| mixed | balance | 180.644 | 666.772 |
| hot | credit | 897.853 | 3241.522 |
| hot | debit | 908.221 | 3261.429 |
| hot | balance | 8.248 | 51.002 |

The host concurrency suite passes with 100 clients. The optional checks profile separately passes the 1,000-simultaneous-client burst on the Docker network, including first-user creation and all returned balances. The Windows published-port burst at 1,000 clients still resets connections and is a known transport limit of this tested setup; it is not counted as a passing host test. Compose down/up preserves the verified balance, token override accepts the new token and rejects the old one, and the original token is restored. Post-load SQL reconciliation and the full historical nonnegative-balance check pass with 10,040,065 ledger rows and 100,075 wallets. Raw reports are in `results/docker-*.json` and `results/docker-*.txt`.

## Query plans

Run `docker compose exec api php artisan ledger:explain` to print MySQL EXPLAIN output for every statement in the credit/debit flow and the balance read. It only explains writes; it does not execute them.

Captured on MySQL 8.4.7 with the 10M dataset; full output: [results/query-plans.txt](results/query-plans.txt). Docker MySQL 8.4.13 plans are captured separately in [results/docker-query-plans.txt](results/docker-query-plans.txt).

| Operation / statement | EXPLAIN select_type | access type | key | rows |
| --- | --- | --- | --- | ---: |
| credit: wallet INSERT / duplicate-key lock | INSERT | ALL | null | null |
| credit/debit: SELECT balance FOR UPDATE | SIMPLE | const | PRIMARY | 1 |
| credit/debit: UPDATE wallet balance | UPDATE | range | PRIMARY | 1 |
| credit/debit: INSERT ledger row | INSERT | ALL | null | null |
| get_balance: SELECT balance | SIMPLE | const | PRIMARY | 1 |

The `ALL` label in MySQL's EXPLAIN of an `INSERT ... VALUES` describes the insert target, with no source rows estimated. It is not a ledger-history scan. The lookup/update statements resolve through the wallet primary key and inspect one wallet regardless of ledger size.

## Limits and further work

At 10x load, a single hot wallet remains sequential; transaction round trips and durable commits become its limit. Across many users, PHP worker capacity, database connection capacity, redo/binlog I/O, buffer pool pressure and the ledger append/index pages become shared resources. A fixed-size worker pool means extreme hot-user saturation can still queue unrelated HTTP requests even though it does not lock their wallet rows.

A signed 64-bit balance has an upper bound of 9,223,372,036,854,775,807 minor units. PHP runs 64-bit. Financial applications needing larger balances or currency conversion need a separate numeric/currency contract.

Repeated client submissions are distinct operations. After a connection failure during commit the client cannot safely infer success; idempotency keys would address that ambiguity. Optional extras were left out to focus on the required contract. With more time: measured fault injection for process/database crashes, explicit overload control, idempotency, telemetry, multiple-instance load runs, more isolated cold-user latency measurements, and dedicated runtime/migration database roles.

Repeated local Windows load runs initially exhausted ephemeral sockets with nonpersistent connections. The runtime now pools database connections, and the local Windows test server disables AcceptEx and uses a larger listen backlog. These operating-system settings are separate from application consistency; final successful measurements reported here use connection pooling.

Docker Desktop 4.99.0 / WSL 3.0.5 now run the Linux engine successfully after the Windows restart. Container build, startup, isolated PHPUnit tests and full-size seed have been exercised. Detailed final checks are recorded in [results/docker-verification.json](results/docker-verification.json). The native benchmark table above remains a separate earlier measurement; Docker results are reported separately.

Framework references: [Laravel transactions and deadlock retries](https://laravel.com/docs/13.x/database#database-transactions), [MySQL locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html).
