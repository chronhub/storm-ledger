<?php

declare(strict_types=1);

namespace Storm\Ledger\Exception;

use LogicException;

/**
 * The app's privacy master key is unusable: absent, not base64, the wrong length, or unable to
 * unwrap stored key material. A misconfiguration, so a `LogicException`, and always LOUD: silently
 * rendering fallbacks because the master key is wrong would present redaction as truth while the
 * real problem is an environment variable.
 */
final class InvalidMasterKey extends LogicException
{
    public static function malformed(): self
    {
        return new self(
            'The privacy master key must be base64 of 32 random bytes — generate one with: php -r "echo base64_encode(random_bytes(32));" and set it as STORM_PRIVACY_MASTER_KEY (or the storm.privacy.master_key config).',
        );
    }

    public static function cannotUnwrap(string $subject): self
    {
        return new self(sprintf(
            'The privacy master key cannot unwrap the stored key material of subject [%s] — the master key changed since the key was issued, or the row is corrupt. Restore the original STORM_PRIVACY_MASTER_KEY; without it every encrypted field of this store is unreadable.',
            $subject,
        ));
    }
}
