<?php

declare(strict_types=1);

namespace Storm\Ledger\Crypto;

use Doctrine\DBAL\Connection;
use Storm\Contracts\Serializer\CipherKeyStore;
use Storm\Ledger\Exception\ForgetIncomplete;
use Storm\Ledger\Exception\PersonalDataNotDeclared;
use Storm\Projector\Definition\ForgetsSubject;
use Storm\Projector\Definition\Projection;
use Storm\Projector\Definition\ReadModel;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\ProjectionLanes;
use Throwable;

use function spl_object_id;

/**
 * The ONE assembly of a forget, shared by its console and HTTP channels: destroy the subject's
 * cipher key, then run every volunteering projection's {@see \Storm\Projector\Definition\ForgetsSubject} hook, grouped by home,
 * each home's volunteers inside ONE transaction so a partial forget never commits, and answer the
 * {@see ForgetOutcome} report naming what ran AND what did not.
 *
 * The key goes first, deliberately. That order is safe because hooks are idempotent by contract: a
 * home that fails rolls back and throws {@see ForgetIncomplete}, and the re-run redoes every hook,
 * while the destroyed key already makes every event read render fallbacks, the stronger half.
 *
 * The projector wiring is optional on purpose: a standalone Ledger, or a query-only app, has no
 * registry and no lanes, and the forget degrades to the key destruction with an empty report,
 * never a compile failure.
 *
 * The store itself is armed only where the compiled `#[Personal]` map is non-empty; an app with
 * no marked class never resolves the master-key env, and this class carries no default to paper
 * over that. `PrivacyForgetCommand`, the one operator-facing edge, refuses before ever calling
 * {@see forget()} in that case, so reaching the guard below means something bypassed it.
 */
final readonly class SubjectForgetter
{
    public function __construct(
        private ?CipherKeyStore $keys = null,
        private ?ProjectionRegistry $registry = null,
        private ?ProjectionLanes $lanes = null,
    ) {}

    /**
     * @throws PersonalDataNotDeclared when armed with no cipher-key store, a wiring defect
     * @throws ForgetIncomplete when a volunteer's hook failed; its home rolled back, the key is
     *                          already destroyed, and a re-run completes the read-model half
     * @throws Throwable on a DBAL failure destroying the key or opening a home's transaction
     */
    public function forget(string $subject): ForgetOutcome
    {
        if ($this->keys === null) {
            throw PersonalDataNotDeclared::forSubjectForgetter();
        }

        $keyDestroyed = $this->keys->destroy($subject);

        $touched = [];

        foreach ($this->volunteersByHome() as [$connection, $entries]) {
            $connection->beginTransaction();

            $name = '';
            $homeTouched = [];

            try {
                foreach ($entries as [$name, $volunteer]) {
                    $volunteer->forgetSubject($subject, $connection);
                    $homeTouched[] = $name;
                }

                $connection->commit();
                // promoted only past the commit: a failing home rolls its siblings back too, and a
                // report naming rolled-back work would be the lie the outcome exists to prevent
                $touched = [...$touched, ...$homeTouched];
            } catch (Throwable $e) {
                try {
                    $connection->rollBack();
                } catch (Throwable) {
                    // the ForgetIncomplete below is the recovery instruction, the one message that
                    // says the key IS destroyed and a re-run completes the rest; a rollback that
                    // cannot run, the connection lost or the transaction already ended by a failed
                    // commit, must not replace it
                }

                // the partial report travels with the failure: on the one path where an operator
                // most needs to know what state things are in, honesty stays structural
                throw ForgetIncomplete::projectionFailed(
                    $subject,
                    $name,
                    $e,
                    new ForgetOutcome($keyDestroyed, $touched, $this->untouched()),
                );
            }
        }

        return new ForgetOutcome($keyDestroyed, $touched, $this->untouched());
    }

    /**
     * The dry-run's halves: who would run, who would not. Touches nothing.
     *
     * @return array{volunteers: list<string>, untouched: list<string>}
     */
    public function preview(): array
    {
        $volunteers = [];

        foreach ($this->projections() as $projection) {
            if ($projection instanceof ForgetsSubject) {
                $volunteers[] = $projection->name();
            }
        }

        return ['volunteers' => $volunteers, 'untouched' => $this->untouched()];
    }

    /**
     * Volunteers grouped by their home's connection: one transaction per DISTINCT home, the
     * single-database default collapsing to one.
     *
     * @return list<array{Connection, non-empty-list<array{string, ForgetsSubject}>}>
     */
    private function volunteersByHome(): array
    {
        if ($this->lanes === null) {
            return [];
        }

        /** @var array<int, array{Connection, non-empty-list<array{string, ForgetsSubject}>}> $homes */
        $homes = [];

        foreach ($this->projections() as $projection) {
            if (! $projection instanceof ForgetsSubject) {
                continue;
            }

            $connection = $this->lanes->laneFor($projection)->connection;

            $homes[spl_object_id($connection)] ??= [$connection, []];
            $homes[spl_object_id($connection)][1][] = [$projection->name(), $projection];
        }

        // @infection-ignore-all; equivalent: the reindex answers the ANALYSER and the declared
        // `list`, the keys being object ids the caller never reads; the one consumer destructures
        // each entry in a foreach, where a gap in the keys changes nothing it can observe.
        return array_values($homes);
    }

    /**
     * Every registered read-model projection with NO hook: the report's honesty half. Other kinds
     * carry no subject rows to forget: link producers write positions, not payloads, and query
     * projections hold no state.
     *
     * @return list<string>
     */
    private function untouched(): array
    {
        $untouched = [];

        foreach ($this->projections() as $projection) {
            if ($projection instanceof ReadModel && ! $projection instanceof ForgetsSubject) {
                $untouched[] = $projection->name();
            }
        }

        return $untouched;
    }

    /**
     * @return array<string, Projection>
     */
    private function projections(): array
    {
        return $this->registry?->all() ?? [];
    }
}
