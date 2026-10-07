<?php

declare(strict_types=1);

namespace Storm\Ledger\Crypto;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use SensitiveParameter;
use SodiumException;
use Storm\Contracts\Serializer\CipherKeyStore;
use Storm\Ledger\Exception\InvalidMasterKey;
use Storm\Ledger\Exception\SubjectAlreadyForgotten;
use Storm\Ledger\Schema\CryptoKeySchema;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The `crypto_keys` implementation of the cipher-key port: one row per subject, the material
 * wrapped by the app's MASTER key with XChaCha20-Poly1305 and the subject id as additional data, so
 * a table dump alone yields no key. Destruction tombstones the row, material nulled and
 * `destroyed_at` set, the schema's bi-implication CHECK holding the two halves together.
 *
 * Timestamps are the database's `now()`: audit metadata on a single-writer-per-subject table, where
 * the database clock is the natural authority, the house precedent of the saga stores.
 *
 * Deliberately NO caching, not even per-request: a worker is long-lived, and material memoized
 * across a forget would keep decrypting a forgotten subject until the process restarts. Correctness
 * over the round-trip, revisit only with a measured need and an eviction story.
 *
 * Exposure limits, honest: `#[SensitiveParameter]` redacts stack traces only, the property stays
 * visible to a container dump or a `var_dump`, and material RETURNED to the serializer lives in the
 * interpreter until collected. The store zeroes the copies it owns, the decoded master and freshly
 * minted material, which is the cheap half of an exposure it cannot fully close.
 *
 * @see CryptoKeySchema
 */
#[AsAlias(CipherKeyStore::class)]
final readonly class DbalCipherKeyStore implements CipherKeyStore
{
    public function __construct(
        private Connection $connection,
        /** Base64 of 32 random bytes; env-provided, never stored. Validated lazily, on first use. */
        #[SensitiveParameter]
        private string $masterKey,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure reading the key row; infrastructure, never a fallback
     * @throws InvalidMasterKey when the master key is malformed or cannot unwrap the stored material
     */
    public function keyFor(string $subject): ?string
    {
        $material = $this->connection->fetchOne(
            /* language=PostgreSQL */
            'SELECT key_material FROM crypto_keys WHERE subject = :subject',
            ['subject' => $subject],
        );

        if (! is_string($material)) {
            return null; // no row means never issued, NULL material means tombstoned: fallbacks either way
        }

        return $this->unwrap($material, $subject);
    }

    /**
     * {@inheritDoc}
     *
     * Concurrency-safe first issue: `INSERT … ON CONFLICT DO NOTHING` then re-read, so two writers
     * racing the same new subject converge on ONE key; a lost race must never mint a second key
     * whose rows become undecryptable when the other one wins the table.
     *
     * @throws Exception on a DBAL failure issuing or reading the key row
     * @throws InvalidMasterKey when the master key is malformed or cannot unwrap the stored material
     */
    public function issue(string $subject): string
    {
        $existing = $this->readForIssue($subject);
        if ($existing !== null) {
            return $existing;
        }

        $material = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

        $this->connection->executeStatement(
            /* language=PostgreSQL */
            'INSERT INTO crypto_keys (subject, key_material, created_at) VALUES (:subject, :material, now())
             ON CONFLICT (subject) DO NOTHING',
            ['subject' => $subject, 'material' => $this->wrap($material, $subject)],
        );

        sodium_memzero($material); // the minted copy is ours alone; the caller gets the re-read below

        // ours or the concurrent winner's: re-read rather than trust the insert, so both racers
        // return the SAME material; a tombstone raced in between still refuses, as it must
        return $this->readForIssue($subject) ?? throw SubjectAlreadyForgotten::forSubject($subject);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure tombstoning the key row
     */
    public function destroy(string $subject): bool
    {
        $tombstoned = $this->connection->executeStatement(
            /* language=PostgreSQL */
            'UPDATE crypto_keys SET key_material = NULL, destroyed_at = now()
             WHERE subject = :subject AND destroyed_at IS NULL',
            ['subject' => $subject],
        );

        if ($tombstoned > 0) {
            return true;
        }

        // no live row: either already tombstoned, the idempotent false, or never issued; record the
        // proof-of-forgetting tombstone; a racing issue() loses to the conflict and the retried
        // UPDATE settles it
        $inserted = $this->connection->executeStatement(
            /* language=PostgreSQL */
            'INSERT INTO crypto_keys (subject, key_material, created_at, destroyed_at) VALUES (:subject, NULL, now(), now())
             ON CONFLICT (subject) DO NOTHING',
            ['subject' => $subject],
        );

        if ($inserted > 0) {
            return true;
        }

        return $this->connection->executeStatement(
            /* language=PostgreSQL */
            'UPDATE crypto_keys SET key_material = NULL, destroyed_at = now()
             WHERE subject = :subject AND destroyed_at IS NULL',
            ['subject' => $subject],
        ) > 0;
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure reading the key row
     */
    public function isDestroyed(string $subject): bool
    {
        // the verdict is cast to int IN SQL so it never rides a driver's boolean rendering: a
        // textual 'f' cast to PHP bool would read every LIVE key as destroyed, a silent total
        // redaction of live data
        return (int) $this->connection->fetchOne(
            /* language=PostgreSQL */
            'SELECT (destroyed_at IS NOT NULL)::int FROM crypto_keys WHERE subject = :subject',
            ['subject' => $subject],
        ) === 1;
    }

    /**
     * Prove the configured master key without touching any subject: a malformed key refuses here,
     * and when a live row exists, one sampled unwrap proves the key is the SAME one the store's
     * rows were wrapped under, so a rotated key refuses at deploy instead of at the first read.
     * `storm:install` runs this when the compiled personal-data map is non-empty.
     *
     * @throws InvalidMasterKey when the key is malformed, or a sampled live row does not unwrap under it
     * @throws Exception on a DBAL failure sampling the row
     */
    public function proveMasterKey(): void
    {
        $key = $this->master();
        sodium_memzero($key);

        /** @var array{subject: string, key_material: string}|false $row */
        $row = $this->connection->fetchAssociative(
            /* language=PostgreSQL */
            'SELECT subject, key_material FROM crypto_keys WHERE key_material IS NOT NULL LIMIT 1',
        );

        if ($row !== false) {
            $material = $this->unwrap($row['key_material'], $row['subject']);
            sodium_memzero($material);
        }
    }

    /**
     * The `issue()`-path read: unwrapped material, null when no row exists yet, refusal on a
     * tombstone.
     *
     * @throws SubjectAlreadyForgotten when the subject is tombstoned
     * @throws Exception on a DBAL failure reading the key row
     * @throws InvalidMasterKey when the master key is malformed or cannot unwrap the stored material
     */
    private function readForIssue(string $subject): ?string
    {
        /** @var array{key_material: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            /* language=PostgreSQL */
            'SELECT key_material FROM crypto_keys WHERE subject = :subject',
            ['subject' => $subject],
        );

        if ($row === false) {
            return null;
        }

        if ($row['key_material'] === null) {
            throw SubjectAlreadyForgotten::forSubject($subject);
        }

        return $this->unwrap($row['key_material'], $subject);
    }

    /**
     * Wrap raw material under the master key as `v1:<base64 nonce>:<base64 ciphertext>`.
     *
     * The subject is additional data, so a wrapped key pasted onto another subject's row fails
     * authentication. This key envelope has its own format, independent of payload field envelopes.
     *
     * @throws InvalidMasterKey when the master key is malformed
     */
    private function wrap(string $material, string $subject): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $master = $this->master();

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($material, $subject, $nonce, $master);
        } catch (SodiumException $e) {
            // master() already gated the length; unreachable in practice, kept as the loud default
            throw new InvalidMasterKey($e->getMessage(), previous: $e); // @codeCoverageIgnore
        } finally {
            sodium_memzero($master);
        }

        return 'v1:'.base64_encode($nonce).':'.base64_encode($ciphertext);
    }

    /**
     * @throws InvalidMasterKey when the master key is malformed or the stored material does not
     *                          unwrap under it; always loud, never a silent fallback, because the
     *                          real problem is an environment variable, not the data
     */
    private function unwrap(string $wrapped, string $subject): string
    {
        $parts = explode(':', $wrapped, 3);

        if (count($parts) !== 3 || $parts[0] !== 'v1') {
            throw InvalidMasterKey::cannotUnwrap($subject);
        }

        $nonce = base64_decode($parts[1], true);
        $ciphertext = base64_decode($parts[2], true);

        if ($nonce === false || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES || $ciphertext === false) {
            throw InvalidMasterKey::cannotUnwrap($subject);
        }

        $master = $this->master();

        try {
            $material = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $subject, $nonce, $master);
        } catch (SodiumException) {
            // decrypt answers false on any malformed ciphertext; it throws on a key or nonce width
            // alone, both gated above, so this is the loud default rather than a live path
            throw InvalidMasterKey::cannotUnwrap($subject); // @codeCoverageIgnore
        } finally {
            sodium_memzero($master);
        }

        if ($material === false) {
            throw InvalidMasterKey::cannotUnwrap($subject);
        }

        return $material;
    }

    /**
     * @throws InvalidMasterKey when the configured value is not base64 of exactly 32 bytes
     */
    private function master(): string
    {
        $key = base64_decode($this->masterKey, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw InvalidMasterKey::malformed();
        }

        return $key;
    }
}
