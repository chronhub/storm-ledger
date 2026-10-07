<?php

declare(strict_types=1);

namespace Storm\Ledger;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Support\Dbal\SchemaCatalog;
use Storm\Support\Dbal\SchemaProbe;

/**
 * Interrogates the PostgreSQL catalogs AFTER the DDL ran and reports every way the installed
 * schema diverges from what the Schema classes declare.
 *
 * `CREATE … IF NOT EXISTS` treats "an object of this name exists" as "the object is the one
 * declared": a pre-existing `stream_heads` without `last_version`, or a homonymous index missing
 * its pending predicate, no-ops the DDL and the install would report success on a schema the
 * runtime cannot use. This probe closes that gap; `storm:install` runs it inside the same
 * transaction as the DDL, so an install either proves its schema or rolls back untouched.
 *
 * The maps below are verification data, not DDL: they mirror what each package's Schema class
 * declares, scoped to what the runtime relies on: columns, partitioning, constraints, indexes and
 * their load-bearing shape. `SchemaCompletenessTest` pins the map against the DDL, so a schema
 * class gaining a table or an index without this map noticing turns a build red.
 *
 * This class is the CORE's catalog and nothing more: the probe itself lives in `Support` so the
 * opt-in Saga, which may not depend on Ledger, verifies its own tables with the same mechanism.
 */
final readonly class SchemaConformance
{
    /**
     * The `event_store` column shape, shared verbatim with its default partition below.
     *
     * @var array<string, string>
     */
    private const array EVENT_STORE_COLUMNS = [
        'category' => 'text not null',
        'sequence_no' => 'bigint not null',
        'stream' => 'text not null',
        'version' => 'bigint not null',
        'type' => 'text not null',
        'event_version' => 'smallint not null',
        'header' => 'jsonb not null',
        'content' => 'jsonb not null',
        'recorded_at' => 'timestamp(6) with time zone not null',
        'xact_id' => 'xid8 not null',
    ];

    /**
     * Every column each table must expose, pinned to the shape the probe composes from the live
     * catalogs, `format_type` plus its nullability. A homonymous table with the right names but a
     * narrower type, a flipped nullability or a different PK passes a name-only probe and fails
     * later, inside an INSERT on a path a backstop may swallow; a null value here would fall back
     * to a presence-only check.
     *
     * @var array<string, array<string, string|null>>
     */
    public const array COLUMNS = [
        'es_idempotency' => [
            'scope_hash' => 'text not null',
            'key_hash' => 'text not null',
            'fingerprint' => 'text not null',
            'message_id' => 'text not null',
            'expires_at' => 'timestamp(6) with time zone not null',
        ],
        'event_store' => self::EVENT_STORE_COLUMNS,
        // a LIST partition's default child carries the parent's columns by construction; ONE
        // spelling serves both so the pair can never be edited apart
        'event_store_default' => self::EVENT_STORE_COLUMNS,
        'event_store_high_water' => [
            'id' => 'smallint not null',
            'position' => 'bigint not null',
            'updated_at' => 'timestamp(6) with time zone not null',
        ],
        'stream_heads' => [
            // the collation is verified, not assumed: a table installed before it was pinned carries
            // the right type and the right nullability, so a shape-only check blesses it while the
            // snapshot sweep, whose explicit `COLLATE "C"` keeps its rows right, loses the primary
            // key it ranges over
            'stream' => 'text not null collate C',
            'last_version' => 'bigint not null',
        ],
        'es_outbox' => [
            'id' => 'bigint not null',
            'position' => 'bigint not null',
            'partition_key' => 'text not null',
            'type' => 'text not null',
            'event_version' => 'smallint not null',
            'status' => 'text not null',
            'attempts' => 'integer not null',
            'last_error' => 'text null',
            'next_attempt_at' => 'timestamp(6) with time zone null',
            'occurred_at' => 'timestamp(6) with time zone not null',
            'failed_at' => 'timestamp(6) with time zone null',
        ],
        'es_outbox_relay' => [
            'relay' => 'text not null',
            'ticked_at' => 'timestamp(6) with time zone not null',
            'relayed_total' => 'bigint not null',
        ],
        'es_outbox_archive' => [
            'id' => 'bigint not null',
            'position' => 'bigint not null',
            'partition_key' => 'text not null',
            'type' => 'text not null',
            'event_version' => 'smallint not null',
            'header' => 'jsonb not null',
            'content' => 'jsonb not null',
            'attempts' => 'integer not null',
            'occurred_at' => 'timestamp(6) with time zone not null',
            'archived_at' => 'timestamp(6) with time zone not null',
        ],
        'es_inbox_consumers' => [
            'id' => 'smallint not null',
            'name' => 'text not null',
        ],
        'es_inbox' => [
            'consumer_id' => 'smallint not null',
            'message_id' => 'bytea not null',
            'processed_at' => 'timestamp(6) with time zone not null',
            'duplicates' => 'integer not null',
            'last_duplicate_at' => 'timestamp(6) with time zone null',
        ],
        'projections' => [
            'name' => 'text not null',
            'status' => 'text not null',
            'last_position' => 'bigint not null',
            'mode' => 'text not null',
            'categories' => 'jsonb not null',
            'event_classes' => 'jsonb not null',
            'source_stream' => 'text null',
            'source_revision' => 'bigint not null',
            'target_stream' => 'text null',
            'target_prefix' => 'text null',
            'lease_owner' => 'text null',
            'lease_until' => 'timestamp(6) with time zone null',
            'last_heartbeat_at' => 'timestamp(6) with time zone null',
            'pause_until' => 'timestamp(6) with time zone null',
            'failed_at' => 'timestamp(6) with time zone null',
            'error_message' => 'text null',
            'error_class' => 'text null',
            'generation' => 'bigint not null',
            'created_at' => 'timestamp(6) with time zone not null',
            'updated_at' => 'timestamp(6) with time zone not null',
        ],
        'event_links' => [
            'target_stream' => 'text not null',
            'target_position' => 'bigint not null',
            'source_sequence' => 'bigint not null',
            'linked_at' => 'timestamp(6) with time zone not null',
        ],
        'event_link_streams' => [
            'target_stream' => 'text not null',
            'revision' => 'bigint not null',
            'rebuilt_at' => 'timestamp(6) with time zone not null',
        ],
        'snapshots' => [
            // pinned and verified for the same reason as `stream_heads.stream`, which it joins: the
            // pair only works pinned on BOTH sides, and a shape-only check would bless a table
            // carrying the right type under the wrong collation
            'stream' => 'text not null collate C',
            'aggregate_type' => 'text not null',
            'version' => 'bigint not null',
            'state' => 'jsonb not null',
            'created_at' => 'timestamp(6) with time zone not null',
        ],
        'crypto_keys' => [
            'subject' => 'text not null',
            'key_material' => 'text null',
            'created_at' => 'timestamp with time zone not null',
            'destroyed_at' => 'timestamp with time zone null',
        ],
    ];

    /**
     * Named constraints per table; the OCC unique and the CHECKs are load-bearing invariants. A
     * non-null value opening on its definition keyword is the complete definition the live
     * `pg_get_constraintdef` must equal, and any other is a fragment it must contain, the rule
     * `ConstraintShape` applies. A name alone proves nothing about a pre-existing homonym, since
     * `ADD CONSTRAINT` converges on `duplicate_object` exactly as `CREATE … IF NOT EXISTS` does,
     * and a CHECK that silently lost a value bites at runtime, not at install.
     *
     * The value-range CHECKs are a SECOND line of defense, not a guard on the normal paths, which
     * cannot produce a negative attempt count or a version of 0. They exist for restores, manual repair
     * and imports, the moments an append-only store has no other way to notice. Shipped inline in the
     * CREATE TABLE only: pre-version posture means an older store is recreated, not converged, and its
     * missing constraint surfaces here as a loud install refusal rather than a silent absence.
     *
     * Values are declared where a degraded homonym bites and where the text is stable across BOTH
     * the source DDL and PostgreSQL's deparse; `SchemaCompletenessTest` pins each value against the
     * rendered DDL, a complete CHECK in its declared form with one parenthesis pair less, and the
     * live probe compares it against `pg_get_constraintdef`:
     *
     * - `es_outbox_status_chk` pins `'failed'`, the dead-letter status: an outbox predating that
     *   disposition passes a name-only probe and the first dead-letter UPDATE explodes at runtime.
     *   The deparse rewrites `IN (…)` to `= ANY (ARRAY[…])`, so the literal is the stable part.
     *
     * - `es_outbox_failed_at_chk` pins the whole bi-implication: with only half of it, a row reads
     *   as a dead letter to one surface and as live work to another.
     *
     * - The numeric floors pin their complete definition, except on `position`, a keyword PostgreSQL
     *   quotes on deparse as `"position" > 0`, where no text is stable across both renderings;
     *   those two stay presence-only, the least-biting degradations of the set.
     *
     * - Every named primary key pins its full column list: `PRIMARY KEY (…)` deparses verbatim, and
     *   a homonym keyed differently misroutes the OCC target and every upsert in silence.
     */
    public const array CONSTRAINTS = [
        'es_idempotency' => [
            'es_idempotency_pk' => 'PRIMARY KEY (scope_hash, key_hash)',
            'es_idempotency_scope_chk' => "scope_hash ~ '^[0-9a-f]{64}$'",
            'es_idempotency_key_chk' => "key_hash ~ '^[0-9a-f]{64}$'",
            'es_idempotency_fingerprint_chk' => "fingerprint ~ '^[0-9a-f]{64}$'",
            'es_idempotency_identity_chk' => 'CHECK ((length(message_id) > 0))',
        ],
        'event_store' => [
            'event_store_pk' => 'PRIMARY KEY (category, sequence_no)',
            'event_store_stream_version_uq' => null,
            'event_store_cat_stream_chk' => "category = split_part(stream, '-'",
            'event_store_version_chk' => 'CHECK ((version > 0))',
            'event_store_event_version_chk' => 'CHECK ((event_version > 0))',
        ],
        'event_store_high_water' => [
            'event_store_high_water_pk' => 'PRIMARY KEY (id)',
            'event_store_high_water_singleton_chk' => 'CHECK ((id = 1))',
            'event_store_high_water_position_chk' => null,
        ],
        'stream_heads' => [
            'stream_heads_pk' => 'PRIMARY KEY (stream)',
            'stream_heads_last_version_chk' => 'CHECK ((last_version >= 0))',
        ],
        'es_outbox' => [
            'es_outbox_pk' => 'PRIMARY KEY (id)',
            'es_outbox_status_chk' => "'failed'",
            'es_outbox_position_chk' => null,
            'es_outbox_event_version_chk' => 'CHECK ((event_version > 0))',
            'es_outbox_attempts_chk' => 'CHECK ((attempts >= 0))',
            'es_outbox_failed_at_chk' => "(failed_at IS NOT NULL) = (status = 'failed'",
        ],
        'es_outbox_relay' => [
            'es_outbox_relay_pk' => 'PRIMARY KEY (relay)',
            'es_outbox_relay_relayed_total_chk' => 'CHECK ((relayed_total >= 0))',
        ],
        'es_outbox_archive' => [
            'es_outbox_archive_pk' => 'PRIMARY KEY (id)',
            'es_outbox_archive_position_chk' => null,
            'es_outbox_archive_attempts_chk' => 'CHECK ((attempts >= 0))',
        ],
        'es_inbox_consumers' => [
            'es_inbox_consumers_pk' => 'PRIMARY KEY (id)',
            'es_inbox_consumers_name_uq' => 'UNIQUE (name)',
        ],
        'es_inbox' => [
            'es_inbox_pk' => 'PRIMARY KEY (consumer_id, message_id)',
            'es_inbox_duplicates_chk' => 'CHECK ((duplicates >= 0))',
            'es_inbox_key_length_chk' => 'CHECK ((octet_length(message_id) = ANY (ARRAY[16, 32])))',
        ],
        'projections' => ['projections_pk' => 'PRIMARY KEY (name)'],
        'event_links' => [
            'event_links_pk' => 'PRIMARY KEY (target_stream, target_position)',
            'event_links_source_uq' => null,
        ],
        'event_link_streams' => ['event_link_streams_pk' => 'PRIMARY KEY (target_stream)'],
        'snapshots' => ['snapshots_pk' => 'PRIMARY KEY (stream)'],
        'crypto_keys' => [
            'crypto_keys_pk' => 'PRIMARY KEY (subject)',
            // the tombstone bi-implication, pinned whole for the same reason as es_outbox_failed_at_chk:
            // with only half of it a row can hold destroyed material without its proof, or the reverse
            'crypto_keys_tombstone_chk' => '(key_material IS NULL) = (destroyed_at IS NOT NULL',
        ],
    ];

    /**
     * Indexes per table; a non-null value is a fragment the live `indexdef` must contain. The
     * pending partial predicate and the correlation expression are what make those two indexes
     * worth having, and `CREATE INDEX IF NOT EXISTS` never verifies a homonym kept them.
     */
    public const array INDEXES = [
        'es_idempotency' => ['es_idempotency_expiration_idx' => 'USING brin (expires_at)'],
        'event_store' => [
            'event_store_sequence_no_idx' => null,
            'event_store_correlation_idx' => "#>> '{__correlation_id}'",
        ],
        'es_outbox' => [
            'es_outbox_pending_idx' => "WHERE (status = 'pending'",
            // the relay's per-partition head probe: index-only or the relay collapses under a
            // chaos-shaped backlog, one partition per row; the fragment pins the leading
            // partition_key column, the INCLUDE that keeps the probe's aggregates index-only,
            // and the partial predicate
            'es_outbox_partition_pending_idx' => "(partition_key, id) INCLUDE (next_attempt_at) WHERE (status = 'pending'",
            // the dead-letter's own partial index; `failed` rows are never disposed of, so this set
            // only grows, and every `storm:outbox:failed` read rides this predicate
            'es_outbox_failed_idx' => "WHERE (status = 'failed'",
        ],
        'es_outbox_archive' => ['es_outbox_archive_age_idx' => null],
        'es_inbox' => [
            'es_inbox_age_idx' => null,
            // the duplicate gauges' partial index: the predicate is what keeps a scrape off the table
            'es_inbox_duplicates_idx' => 'WHERE (duplicates > 0)',
        ],
    ];

    /** Tables that must be partitioned parents, with `relkind = 'p'`. */
    public const array PARTITIONED = ['event_store'];

    /**
     * The catch-all every category that owns no partition of its own routes to, and it must be
     * ATTACHED, not merely present: a detached `event_store_default` keeps its name and its shape,
     * so the DDL skips it and the parent still verifies as partitioned while an event of an unnamed
     * category has nowhere to land.
     */
    public const array DEFAULT_PARTITIONS = ['event_store' => 'event_store_default'];

    /**
     * `sequence_no` is `GENERATED ALWAYS AS IDENTITY`: the global monotone position and the safe
     * head's own gap scan. A pre-existing column of the right type and nullability but no
     * generator accepts an explicit value silently and never produces one on an omitted insert,
     * which the column-shape check alone cannot see. The default partition shares the parent's
     * columns by construction, so it carries the same entry.
     */
    public const array IDENTITIES = [
        'event_store' => ['sequence_no' => 'a'],
        'event_store_default' => ['sequence_no' => 'a'],
        'es_inbox_consumers' => ['id' => 'a'],
    ];

    /**
     * `xact_id` defaults to `pg_current_xact_id()`, forensic data the safe head's writer-liveness
     * classification reads. A pre-existing column of the right type keeps NOT NULL satisfied by a
     * wrong or missing default, so nothing here fails a single insert; the classification it feeds
     * would just be wrong, silently, which is the case the column-shape check alone cannot see.
     */
    public const array DEFAULTS = [
        'event_store' => ['xact_id' => 'pg_current_xact_id()'],
        'event_store_default' => ['xact_id' => 'pg_current_xact_id()'],
    ];

    /**
     * The declared shape as verification DATA, so the shared probe can read it: the maps above,
     * handed over unchanged.
     */
    public static function catalog(): SchemaCatalog
    {
        return new SchemaCatalog(self::COLUMNS, self::CONSTRAINTS, self::INDEXES, self::PARTITIONED, self::DEFAULT_PARTITIONS, self::IDENTITIES, self::DEFAULTS);
    }

    /**
     * Report every divergence between the live schema in the connection's `current_schema()`
     * and the declared one, for the given tables.
     *
     * @param  list<string>  $tables  the subset owned by this connection; the read-model side
     *                                of a split owns `projections` only. Every name must be a key
     *                                of the conformance maps; an unknown one is itself reported,
     *                                since unverifiable must never read as verified
     * @return list<string> human-readable problems; empty means the schema verified
     *
     * @throws Exception on a DBAL failure interrogating the catalogs
     */
    public function problems(Connection $connection, array $tables): array
    {
        return new SchemaProbe()->problems($connection, self::catalog(), $tables);
    }
}
