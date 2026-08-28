<?php

declare(strict_types=1);

namespace Storm\Ledger\Exception;

use RuntimeException;
use Storm\Contracts\Serializer\SubjectForgotten;

/**
 * Implements the contracted `SubjectForgotten` refusal on a `RuntimeException`: the store was
 * asked to issue a key for a tombstoned subject.
 */
final class SubjectAlreadyForgotten extends RuntimeException implements SubjectForgotten
{
    public static function forSubject(string $subject): self
    {
        return new self(sprintf(
            'Subject [%s] is forgotten — its cipher key is tombstoned, and issuing a new one would silently resurrect the identity. A legitimate return needs a NEW subject id.',
            $subject,
        ));
    }
}
