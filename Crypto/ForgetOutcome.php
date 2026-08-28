<?php

declare(strict_types=1);

namespace Storm\Ledger\Crypto;

/**
 * What one forget actually did: the report both channels render, console and HTTP, so the two can
 * never tell different stories. Honesty is structural: `untouched` names every registered read-model
 * projection that did NOT volunteer a {@see \Storm\Projector\Definition\ForgetsSubject} hook, because a
 * compliance answer that hides what it skipped is a lie.
 */
final readonly class ForgetOutcome
{
    /**
     * @param  bool  $keyDestroyed  true when THIS run destroyed the key; false when the subject was
     *                              already tombstoned and the run was the idempotent re-forget
     * @param  list<string>  $touched  volunteer projections whose hook ran and committed, in run order
     * @param  list<string>  $untouched  registered read-model projections with NO hook; their rows,
     *                                   if any hold the subject, are only caught up by reset + rebuild
     */
    public function __construct(
        public bool $keyDestroyed,
        public array $touched,
        public array $untouched,
    ) {}
}
