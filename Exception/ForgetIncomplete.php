<?php

declare(strict_types=1);

namespace Storm\Ledger\Exception;

use RuntimeException;
use Storm\Ledger\Crypto\ForgetOutcome;
use Throwable;

/**
 * A volunteer's forget hook failed and its home's transaction was rolled back: the KEY is already
 * destroyed, since it goes first by design and a re-run redoes every hook idempotently, but the
 * read-model half is incomplete, and an operator must see which projection stopped it rather than a
 * green lie. The partial report travels along, so the failure path stays as honest about scope as
 * the success path.
 */
final class ForgetIncomplete extends RuntimeException
{
    private function __construct(string $message, ?Throwable $previous, public readonly ?ForgetOutcome $outcome)
    {
        parent::__construct($message, previous: $previous);
    }

    /**
     * @param  ForgetOutcome|null  $outcome  what had already run when the hook failed: the key
     *                                       verdict, the committed volunteers, the never-covered
     */
    public static function projectionFailed(string $subject, string $projection, Throwable $previous, ?ForgetOutcome $outcome = null): self
    {
        return new self(sprintf(
            'Forget of subject [%s] is INCOMPLETE: the ForgetsSubject hook of projection [%s] failed and its home transaction was rolled back — the cipher key IS destroyed; fix the cause and re-run (hooks are idempotent), or reset + rebuild the projection.',
            $subject,
            $projection,
        ), $previous, $outcome);
    }
}
