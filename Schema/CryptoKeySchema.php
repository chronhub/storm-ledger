<?php

declare(strict_types=1);

namespace Storm\Ledger\Schema;

use Storm\Ledger\Crypto\DbalCipherKeyStore;

/**
 * Raw PostgreSQL DDL for `crypto_keys`, the per-subject cipher keys behind crypto-shredding.
 *
 * One row per subject. `key_material` is the subject's key, itself encrypted by the app's master
 * key so a table dump alone yields nothing. Destruction is a TOMBSTONE, never a DELETE: the
 * material is nulled and `destroyed_at` set, so the forgetting is provable, an audit asking "when",
 * and idempotent; the bi-implication CHECK is the invariant that a row is exactly one of
 * {issued, tombstoned}, never a half-state a restore or manual repair could leave.
 *
 * Lives in Ledger rather than Serializer on purpose: the Serializer package stays dependency-free
 * outside DI, while Ledger already owns the core install; the consuming port is
 * `Storm\Contracts\Serializer\CipherKeyStore`.
 *
 * @see DbalCipherKeyStore
 */
final class CryptoKeySchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS crypto_keys (
                    subject       text        NOT NULL,
                    key_material  text            NULL,
                    created_at    timestamptz NOT NULL,
                    destroyed_at  timestamptz     NULL,
                    CONSTRAINT crypto_keys_pk PRIMARY KEY (subject),
                    -- the tombstone bi-implication: destroyed material and the destruction proof travel
                    -- together, so no restore or manual repair can leave a key without its verdict
                    CONSTRAINT crypto_keys_tombstone_chk CHECK ((key_material IS NULL) = (destroyed_at IS NOT NULL))
                )
                SQL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function down(): array
    {
        return [
            /** @lang PostgreSQL */
            'DROP TABLE IF EXISTS crypto_keys',
        ];
    }
}
