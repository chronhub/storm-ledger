<?php

declare(strict_types=1);

namespace Storm\Ledger\Exception;

use LogicException;

/**
 * A crypto-shredding consumer ran with no cipher-key store bound. The bundle only ever arms one
 * when the compiled `#[Personal]` map is non-empty, so this app declares no personal data at all;
 * erasing a subject's key does not apply to it. A wiring defect rather than an operator mistake:
 * the store's one operator-facing edge, `PrivacyForgetCommand`, refuses loud before ever reaching
 * `SubjectForgetter`, so this exception firing at all means something else called it directly.
 */
final class PersonalDataNotDeclared extends LogicException
{
    public static function forSubjectForgetter(): self
    {
        return new self(
            'SubjectForgetter was armed with no cipher-key store: this app declares no #[Personal] class, so crypto-shredding does not apply to it.',
        );
    }
}
