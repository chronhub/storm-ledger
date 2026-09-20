# Storm Ledger — the framework's own schema, installed

The Ledger module owns two framework-side responsibilities: **Storm's schema bootstrap** — one
console command, `storm:install`, that aggregates the raw DDL the core packages declare, runs it
against the configured connection and PROVES what it created — and **the key half of
crypto-shredding** — the cipher key store and the `storm:privacy:forget` assembly that makes a
subject's personal data unreadable everywhere at once.

The bootstrap is the framework-side counterpart of the application's Doctrine migrations, and the
two never mix: Storm's tables belong to Storm packages; application tables belong to the app and
its own migration tooling.

## Doctrine: each package owns its schema

There is **no `LedgerMigration` / `LedgerRunner`** — that design was evaluated and rejected.
Each package declares its own `*Schema` class with
`up()` / `down()` in **raw DDL**, because the interesting parts — `PARTITION BY LIST`, CHECK
constraints, `GENERATED ALWAYS AS IDENTITY`, partial and expression indexes — are inexpressible
in doctrine-migrations. Every `up()` is idempotent (`CREATE … IF NOT EXISTS`), so the install is
safe to re-run.

`storm:install` aggregates the core schemas — event store, stream heads, high-water, outbox,
inbox (Chronicler), projections and event links (Projector), snapshots (AggregateRepository) —
in three mutually exclusive modes:

```bash
bin/console storm:install                   # up() only: create, idempotent, verified
bin/console storm:install --drop --force    # down() only: drop without recreating
bin/console storm:install --reset --force   # down() then up(): a clean cycle
```

`--drop` and `--reset` destroy the event store — the source of truth, not a rebuildable cache —
so both ask an interactive confirmation naming the target database(s) and schema(s); `--force`
answers it, and is required when the session is non-interactive.

## Verified, transactional, serialized

Per connection, the whole install/drop/reset runs in **one transaction under an advisory lock**:
a mid-run failure rolls the entire side back — no half-installed schema to diagnose — and two
concurrent installs/resets cannot interleave. On a split read-model-store topology the two sides
are two transactions (events first, then the store side's `projections`); a store-side failure
leaves the events side complete and verified, and a re-run completes the store side.

`IF NOT EXISTS` is a re-run defense, **never** a conformance proof: PostgreSQL treats "an object
of this name exists" as satisfied without comparing columns, constraints or index definitions.
After the DDL, `SchemaConformance` interrogates the catalogs — columns, partitioning, named
constraints, indexes and their load-bearing shape (the outbox pending predicate, the correlation
expression) — and a pre-existing incompatible homonym fails the install loud, with the divergence
listed and the transaction rolled back. An install that reports success has *proven* its schema.

Before anything runs, the command prints each connection's `current_database()` /
`current_schema()` / server version (the DDL is `search_path`-relative by design — the operator
sees where it lands) and refuses a server below PostgreSQL 17, the schema's documented floor.

Opt-in packages install their own tables with their own commands, same doctrine:
`storm:saga:install` (the workflow tables) and `storm:telemetry:install` (`workflow_history`).

## Crypto-shredding — the key half

`DbalCipherKeyStore` implements the Contracts `CipherKeyStore` seam: one key per subject, and a
destroyed key leaves a tombstone — issuing a key for a forgotten subject surfaces
`SubjectForgotten`, because new personal data about a forgotten person is a compliance bug, not a
condition to absorb. The codec half (encrypt at rest, render fallbacks after the forget) lives in
the Serializer package; the read-model half (`ForgetsSubject` volunteers) in the Projector.

`storm:privacy:forget <subject>` is the one assembly: it destroys the subject's key FIRST — the
stronger half, every event read renders fallbacks from that instant — then runs each home's
volunteering projections inside one transaction per home, and reports both what ran and what did
NOT volunteer. The order is safe because hooks are idempotent by contract: a failed home throws
`ForgetIncomplete` and the re-run redoes every hook. A standalone Ledger without a projector
degrades to the key destruction with an empty report, never a wiring failure.

## What this module does NOT do

- **Schema CHANGE.** `IF NOT EXISTS` only *creates*; evolving a deployed table without data loss
  is a deferred capability (per-package Doctrine migrations, triggered by the first production
  schema change). Until then, an addition to a deployable table carries a "pre-existing installs"
  note in its schema docblock — those notes are the living backlog of that future path.
- **Application schema.** Read-model tables an app owns through `MigratedReadModel`, and any
  domain table, are the app's business — Doctrine migrations app-side.
- **Environment provisioning.** Databases, schemas (in the PostgreSQL sense), and per-side
  provisioning under a split read-model-store topology are operator moves; the command runs
  against the one connection it is given.

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Experimental 0.x: this package changes without deprecation cycles — no backward-compatibility
promise and no legacy layer. Pin an exact 0.x tag or commit for reproducibility; pinning fixes
history, not a stable API. Schema changes are resets, not migrations, and a reset destroys data,
so it stays on disposable environments.*
