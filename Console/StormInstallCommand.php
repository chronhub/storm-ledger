<?php

declare(strict_types=1);

namespace Storm\Ledger\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Override;
use Storm\AggregateRepository\Schema\SnapshotSchema;
use Storm\Chronicler\Evolution\UpcasterConformance;
use Storm\Chronicler\SafeHead\SafeHeadPrecondition;
use Storm\Chronicler\Schema\EventStoreHighWaterSchema;
use Storm\Chronicler\Schema\EventStoreSchema;
use Storm\Chronicler\Schema\InboxSchema;
use Storm\Chronicler\Schema\OutboxArchiveSchema;
use Storm\Chronicler\Schema\OutboxSchema;
use Storm\Chronicler\Schema\StreamHeadsSchema;
use Storm\EventLinks\Schema\EventLinkSchema;
use Storm\EventLinks\Schema\EventLinkStreamSchema;
use Storm\Ledger\Crypto\DbalCipherKeyStore;
use Storm\Ledger\Exception\InvalidMasterKey;
use Storm\Ledger\Schema\CryptoKeySchema;
use Storm\Ledger\SchemaConformance;
use Storm\Projector\Schema\ProjectionSchema;
use Storm\Support\Dbal\InstallLock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Bootstraps the CORE Storm tables: the Chronicler event store and its outbox, the projector
 * tables, the aggregate snapshots and the cipher keys. Runs the raw DDL each schema declares, the
 * same statements the integration tests apply; the opt-in packages install their own.
 *
 * Three mutually exclusive modes:
 *
 * - No flag: `up()` only; create the tables, idempotent `IF NOT EXISTS`, safe to re-run.
 * - `--drop`: `down()` only; drop the tables, do not recreate.
 * - `--reset`: `down()` then `up()`; drop and recreate for a clean cycle.
 *
 * `--drop` and `--reset` destroy the event store, the source of truth rather than a rebuildable
 * cache, so both demand an interactive confirmation naming the target database and schema, or
 * `--force` when no interaction is possible.
 *
 * Guarantees, per connection:
 *
 * - Everything runs in ONE transaction under an advisory lock: a failure rolls the whole side
 *   back, leaving no half-installed schema and no interleaving with a concurrent install or
 *   reset. On a split read-model-store topology the two sides are two transactions; events run
 *   first, then the store side's `projections`; a store-side failure leaves the events side
 *   complete and verified, and a re-run completes the store side, made idempotent by
 *   `IF NOT EXISTS`.
 *
 * - `IF NOT EXISTS` is a re-run defense, never a conformance proof: after the DDL the catalogs
 *   are interrogated by {@see SchemaConformance}, and a pre-existing incompatible homonym such
 *   as a `stream_heads` without `last_version` or an index that lost its predicate fails the
 *   install loud with the divergence listed, transaction rolled back, before any success is
 *   reported.
 *
 * - When the compiled `#[Personal]` map is non-empty, the privacy MASTER key is proven with the
 *   same discipline: well-formed, and unwrapping one sampled existing row, so a malformed or
 *   rotated key refuses at deploy instead of at the first personal event in production traffic.
 *
 * - PostgreSQL 17 is verified up front, the schema's documented floor for identity on a
 *   partitioned table, and each connection's `current_database()` / `current_schema()` / server
 *   version is printed before anything runs; the schema is `search_path`-relative by design, so
 *   the operator sees where the DDL lands.
 *
 * Evolving an already-deployed table is a deferred capability; until then a schema change is a
 * reset and not a migration, which is why no schema documents a migration path.
 *
 * Examples:
 *
 * ```bash
 * # Create the tables, idempotent, verified
 * bin/console storm:install
 * ```
 *
 * ```bash
 * # Drop the tables without recreating them (asks; --force skips the question)
 * bin/console storm:install --drop --force
 * ```
 *
 * ```bash
 * # Drop and recreate for a clean cycle
 * bin/console storm:install --reset --force
 * ```
 */
#[AsCommand(
    name: 'storm:install',
    description: 'Create the Storm event-store + projector tables, verified (--drop to drop only; --reset to drop + recreate; both ask unless --force).',
)]
final class StormInstallCommand extends Command
{
    /** "STORMINS" packed as an int64; the advisory lock serializing installs/resets per database. */
    private const int ADVISORY_LOCK_KEY = 0x53544F524D494E53;

    /** Identity on the partitioned `event_store` table makes PostgreSQL 17 the schema's floor. */
    private const int MINIMUM_SERVER_VERSION_NUM = 170_000;

    /**
     * @param  Connection|null  $readModels  the read-model store connection of per-projection
     *                                       homing: `projections` is created THERE as well as on
     *                                       the events side. Null, standalone with no Projector
     *                                       wiring, or a single database mean the same connection
     *                                       and the second pass is skipped or idempotent.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?Connection $readModels = null,
        private readonly SchemaConformance $conformance = new SchemaConformance,
        /**
         * Autowired in prod, carrying the deployment's configured grace; null in a manual wiring
         * simply skips the note, since it reports on the runtime, never on the schema this command
         * proves.
         */
        private readonly ?SafeHeadPrecondition $safeHead = null,
        /**
         * The data-level readability conformance, run in the same transaction when the event side
         * carries rows: every stored row must be readable by this binary's upcaster chain. Null in
         * a manual wiring skips it; a fresh install passes it trivially.
         */
        private readonly ?UpcasterConformance $upcasters = null,
        /**
         * Wired by the bundle ONLY when the compiled personal-data map is non-empty, so an app
         * without `#[Personal]` classes never resolves the master-key env. Present, the install
         * proves the key the same way it proves the schema: malformed or rotated refuses at
         * deploy, never at the first personal event on a production write path.
         */
        private readonly ?DbalCipherKeyStore $privacyKeys = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('drop', null, InputOption::VALUE_NONE, 'Drop the tables (no re-create). Mutually exclusive with --reset.');
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Reset: drop the tables then re-create them. Mutually exclusive with --drop.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Confirm --drop/--reset without asking (required when the session is non-interactive).');
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure creating / dropping the Storm tables; the failing
     *                   connection's transaction is rolled back first, nothing half-installed
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $drop = (bool) $input->getOption('drop');
        $reset = (bool) $input->getOption('reset');

        if ($drop && $reset) {
            $io->error('--drop and --reset are mutually exclusive. --drop drops only; --reset drops and re-creates.');

            return Command::INVALID;
        }

        $store = $this->readModels ?? $this->connection;
        $split = $store !== $this->connection;

        $homes = [['events', ...$this->home($this->connection)]];
        if ($split) {
            $homes[] = ['read-models', ...$this->home($store)];
        }

        foreach ($homes as [$side, $database, $schema, $version, $versionNum]) {
            $io->text(sprintf('%-12s %s / %s — PostgreSQL %s', $side, $database, $schema, $version));
            if ($versionNum < self::MINIMUM_SERVER_VERSION_NUM) {
                $io->error(sprintf(
                    'PostgreSQL 17 is the schema floor (identity on a partitioned table) — the %s side runs %s.',
                    $side,
                    $version,
                ));

                return Command::FAILURE;
            }
        }

        if ($drop || $reset) {
            $target = implode(' + ', array_map(static fn (array $home): string => sprintf('%s/%s', $home[1], $home[2]), $homes));
            if (! (bool) $input->getOption('force')) {
                if (! $input->isInteractive()) {
                    $io->error(sprintf(
                        '--%s destroys the event store (the source of truth) on %s and the session is non-interactive: pass --force to confirm.',
                        $drop ? 'drop' : 'reset',
                        $target,
                    ));

                    return Command::INVALID;
                }
                if (! $io->confirm(sprintf('%s the Storm tables on %s?', $drop ? 'Drop' : 'Drop and re-create', $target), false)) {
                    $io->warning('Aborted — nothing was dropped.');

                    return Command::SUCCESS;
                }
            }
        }

        $problems = $this->runSide(
            $this->connection,
            ($drop || $reset) ? $this->down() : [],
            $drop ? [] : $this->up(),
            array_keys(SchemaConformance::COLUMNS),
        );
        if ($problems === [] && $split) {
            // the store side owns `projections` only; the events side just verified everything else
            $problems = $this->runSide(
                $store,
                ($drop || $reset) ? ProjectionSchema::down() : [],
                $drop ? [] : ProjectionSchema::up(),
                ['projections'],
            );
        }

        if ($problems !== []) {
            $io->error([
                'The Storm schema did not verify — the transaction was rolled back, this connection is unchanged:',
                ...$problems,
            ]);

            return Command::FAILURE;
        }

        if (! $drop && $this->privacyKeys !== null) {
            // the module's own argument, applied to its one exempt value: configuration whose rot
            // makes the store unreadable fails at install, never at the first personal event on a
            // production write path; a sampled unwrap is what catches the ROTATED key, which would
            // issue happily for new subjects while every existing one refuses
            try {
                $this->privacyKeys->proveMasterKey();
            } catch (InvalidMasterKey $e) {
                $io->error([
                    'The privacy master key did not verify — the schema is installed, but every #[Personal] field would fail at its first read or write:',
                    $e->getMessage(),
                ]);

                return Command::FAILURE;
            }
        }

        $io->success(match (true) {
            $drop => 'Storm schema dropped.',
            $reset => 'Storm schema reset.',
            default => 'Storm schema installed and verified.',
        });

        if (! $drop) {
            $this->reportSafeHead($io);
            $this->reportSchemaExposure($io, $split ? ['events' => $this->connection, 'read-models' => $store] : ['events' => $this->connection]);
        }

        return Command::SUCCESS;
    }

    /**
     * Report the one exposure a structural verdict cannot cover: a schema on this connection's
     * `search_path` that PUBLIC may CREATE in.
     *
     * Storm resolves every table unqualified, by design, which puts the effective `search_path`
     * inside its trust boundary. PostgreSQL treats a schema an untrusted role can create in as one
     * whose owner you trust: an object planted there shadows the function or operator an unqualified
     * name resolves to, ahead of the schema that was meant to answer. The install cannot close that
     * without dictating a deployment shape to every consumer, so it names what it found and leaves
     * the decision where it belongs.
     *
     * Silent on a database that grants nothing, which PostgreSQL 15 onward is by default; it speaks
     * on the inherited ones, where `public` still carries its old grant.
     *
     * @param  array<string, Connection>  $homes  the sides this run installed on, keyed by their label
     *
     * @throws Exception on a DBAL failure of the grant probe
     */
    private function reportSchemaExposure(SymfonyStyle $io, array $homes): void
    {
        $exposed = [];

        foreach ($homes as $side => $connection) {
            /** @var list<string> $schemas */
            $schemas = $connection->fetchFirstColumn(
                /** @lang PostgreSQL */
                "SELECT n.nspname
                 FROM unnest(current_schemas(false)) AS s(name)
                 JOIN pg_namespace n ON n.nspname = s.name
                 WHERE has_schema_privilege('public', n.oid, 'CREATE')",
            );

            foreach ($schemas as $schema) {
                $exposed[] = sprintf('%s: any role may create objects in "%s", which is on the search_path.', $side, $schema);
            }
        }

        if ($exposed === []) {
            return;
        }

        $io->warning([
            'Schema exposure — the verdict above is structural and reads no grant:',
            ...$exposed,
            'An object planted there resolves ahead of the one an unqualified name meant to reach. REVOKE CREATE ON SCHEMA <name> FROM PUBLIC, or keep Storm on a schema no untrusted role can write to.',
        ]);
    }

    /**
     * Report, after the schema is proven and committed, what this connection can see about the one
     * runtime assumption the store cannot verify for itself: that no append outlives the safe-head
     * grace. See {@see \Storm\Chronicler\SafeHead\SafeHeadPrecondition} for what such a reading does and does not establish.
     *
     * A note, deliberately not a failure, and the reasoning is worth keeping: this command's verdict
     * is about the SCHEMA, which it just proved and committed, whereas `transaction_timeout` is
     * mutable runtime configuration that can change five minutes from now without touching a table;
     * failing here would tie a durable verdict to a volatile fact. And the setting is connection-wide:
     * a value low enough to satisfy the grace also bounds the projection batches and this very
     * installer's DDL, so a framework that refused to install without it would be pushing operators
     * toward a setting it cannot scope for them. What it CAN do is make sure nobody discovers the
     * condition from a read-model that quietly lost an event.
     *
     * @throws Exception on a DBAL failure interrogating the settings / activity views
     */
    private function reportSafeHead(SymfonyStyle $io): void
    {
        $problems = $this->safeHead?->problems() ?? [];

        if ($problems === []) {
            return;
        }

        $io->warning([
            'Safe head — projection completeness is conditional on this deployment:',
            ...$problems,
            'A skipped event is permanent and silent, not lag. Bound the writers below the grace (a writer-only role or DSN: ALTER ROLE … SET transaction_timeout, or options=-c transaction_timeout=…), or raise storm.safe_head.grace_seconds above what your writers really take.',
        ]);
    }

    /**
     * One side's whole install/drop/reset: a single transaction under the advisory lock, the
     * conformance probe before the commit; the side either proves its schema or rolls back.
     *
     * @param  list<string>  $down  statements to run first, in drop / reset modes
     * @param  list<string>  $up  statements to run next, in install / reset modes
     * @param  list<string>  $tables  the tables this side must prove after `up()`
     * @return list<string> conformance problems if the schema diverged and the transaction was rolled back; empty on success
     *
     * @throws Exception on a DBAL failure, after rolling the side's transaction back
     */
    private function runSide(Connection $connection, array $down, array $up, array $tables): array
    {
        $connection->beginTransaction();

        try {
            InstallLock::acquire($connection, self::ADVISORY_LOCK_KEY, 'storm:install');

            foreach ([...$down, ...$up] as $statement) {
                $connection->executeStatement($statement);
            }

            if ($up !== []) {
                $problems = $this->conformance->problems($connection, $tables);

                if ($problems === [] && $this->upcasters !== null && in_array('event_store', $tables, true)) {
                    // the data-level half, same transaction: a re-install over existing rows must also
                    // prove this binary's upcaster chain can read every one of them
                    $problems = $this->upcasters->problems($connection);
                }

                if ($problems !== []) {
                    $connection->rollBack();

                    return $problems;
                }
            }

            $connection->commit();
        } catch (Throwable $e) {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                // the cause below is the real diagnosis: a rollback that cannot run, the connection
                // lost mid-DDL or the transaction already ended by a failed commit, must not
                // replace the statement that actually failed
            }

            throw $e;
        }

        return [];
    }

    /**
     * Where this connection's DDL lands. The schema is `search_path`-relative by design, the
     * application pivoting both sides through it, so the target is printed, never assumed.
     *
     * @return array{string, string, string, int} database, schema, server version, numeric version
     *
     * @throws Exception on a DBAL failure probing the connection
     */
    private function home(Connection $connection): array
    {
        /** @var array{db: string, schema: string, version: string, version_num: int|string} $row */
        $row = $connection->fetchAssociative(
            /** @lang PostgreSQL */
            "SELECT current_database() AS db, current_schema() AS schema,
                    current_setting('server_version') AS version,
                    current_setting('server_version_num')::int AS version_num",
        );

        return [$row['db'], $row['schema'], $row['version'], (int) $row['version_num']];
    }

    /**
     * @return list<string>
     */
    private function up(): array
    {
        return [
            ...EventStoreSchema::up(),
            ...EventStoreHighWaterSchema::up(),
            ...StreamHeadsSchema::up(),
            ...OutboxSchema::up(),
            ...OutboxArchiveSchema::up(),
            ...InboxSchema::up(),
            ...ProjectionSchema::up(), // BOTH sides: the link producers' events-side home; the store side runs its own pass
            ...EventLinkSchema::up(),
            ...EventLinkStreamSchema::up(),
            ...SnapshotSchema::up(),
            ...CryptoKeySchema::up(),
        ];
    }

    /**
     * @return list<string>
     */
    private function down(): array
    {
        return [
            ...CryptoKeySchema::down(),
            ...SnapshotSchema::down(),
            ...EventLinkStreamSchema::down(),
            ...EventLinkSchema::down(),
            ...ProjectionSchema::down(),
            ...InboxSchema::down(),
            ...OutboxArchiveSchema::down(),
            ...OutboxSchema::down(),
            ...StreamHeadsSchema::down(),
            ...EventStoreHighWaterSchema::down(),
            ...EventStoreSchema::down(),
        ];
    }
}
