<?php

declare(strict_types=1);

namespace Storm\Ledger\Tests\Crypto;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Chronicler\Record\EventRecord;
use Storm\Contracts\Serializer\CipherKeyStore;
use Storm\Ledger\Crypto\SubjectForgetter;
use Storm\Ledger\Exception\ForgetIncomplete;
use Storm\Ledger\Exception\PersonalDataNotDeclared;
use Storm\Projector\Definition\ForgetsSubject;
use Storm\Projector\Definition\LinkProjection;
use Storm\Projector\Definition\ReadModel;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\ProjectionLane;
use Storm\Projector\Run\ProjectionLanes;
use Storm\Projector\Store\ProjectionStore;
use Storm\Stream\StreamName;

/**
 * A forget answers two questions an operator is entitled to: what was erased, and what still holds
 * the subject. Both halves were unproved, which on an erasure path is the one place a silent gap is
 * not a style question: a hook loop that never runs erases nothing, and a report that under-names
 * what it did not touch says the data is gone while read models still carry it.
 *
 * Only the assembly is under test here, which is what this class is: the key store, the registry and
 * the lanes are collaborators, and what a real projection does inside its hook belongs to the
 * projection.
 */
final class SubjectForgetterTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function every_volunteer_is_asked_to_forget_and_the_report_names_each_one(): void
    {
        // the loop IS the erasure: emptied, the key still dies and the report still reads like a
        // success, while every read model keeps its rows
        $first = $this->volunteer('rm_customer');
        $second = $this->volunteer('rm_invoice');

        $outcome = $this->forgetter([$first, $second])->forget('subject-1');

        self::assertTrue($first->forgotten);
        self::assertTrue($second->forgotten);
        self::assertSame(['subject-1', 'subject-1'], [$first->subject, $second->subject]);
        self::assertSame(['rm_customer', 'rm_invoice'], $outcome->touched);
        self::assertTrue($outcome->keyDestroyed);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_report_names_every_read_model_that_keeps_the_subject(): void
    {
        // the honesty half, and it must be complete: naming one of two silent read models is the
        // answer an operator would act on believing the rest was erased
        // the silent one comes FIRST on purpose: the scan that collects volunteers skips non-hooks,
        // and a scan that STOPPED at the first of them would answer an empty erasure whenever a
        // read model without a hook happens to be registered before one with it, which is nothing
        // but declaration order
        $outcome = $this->forgetter([
            $this->silent('rm_audit'),
            $this->volunteer('rm_customer'),
            $this->silent('rm_export'),
        ])->forget('subject-1');

        self::assertSame(['rm_customer'], $outcome->touched);
        self::assertSame(['rm_audit', 'rm_export'], $outcome->untouched);
    }

    #[Test]
    #[Group('adversarial')]
    public function armed_with_no_cipher_key_store_it_refuses_rather_than_no_op(): void
    {
        // the operator-facing edge, PrivacyForgetCommand, refuses before ever calling here; reaching
        // this guard means something bypassed it, and a silent no-op would report a forget as done
        // while destroying nothing
        $this->expectException(PersonalDataNotDeclared::class);

        new SubjectForgetter()->forget('subject-1');
    }

    #[Test]
    public function the_preview_answers_both_halves_without_touching_anything(): void
    {
        $volunteer = $this->volunteer('rm_customer');

        $preview = $this->forgetter([$volunteer, $this->silent('rm_audit')])->preview();

        self::assertSame(['volunteers' => ['rm_customer'], 'untouched' => ['rm_audit']], $preview);
        self::assertFalse($volunteer->forgotten);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_deployment_with_no_projector_wiring_still_destroys_the_key(): void
    {
        // a standalone Ledger, or a query-only app, has no registry and no lanes: the forget degrades
        // to its stronger half rather than failing, and the report says plainly that it ran nothing
        $keys = $this->keys();

        $outcome = new SubjectForgetter($keys)->forget('subject-1');

        self::assertTrue($outcome->keyDestroyed);
        self::assertSame([], $outcome->touched);
        self::assertSame([], $outcome->untouched);
        self::assertSame(['subject-1'], $keys->destroyed);
    }

    #[Test]
    public function a_registry_without_lanes_reports_what_it_cannot_run(): void
    {
        // the halves are independent: knowing WHICH read models exist does not mean having a home to
        // run their hooks on, and the report must not claim work it had no lane for
        $registry = new ProjectionRegistry([$this->volunteer('rm_customer'), $this->silent('rm_audit')]);

        $outcome = new SubjectForgetter($this->keys(), $registry)->forget('subject-1');

        self::assertSame([], $outcome->touched);
        self::assertSame(['rm_audit'], $outcome->untouched);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_home_commits_its_volunteers_together(): void
    {
        // one transaction per home is what makes a partial forget impossible: without the open, each
        // hook would stand alone and a failure halfway would leave the earlier ones committed
        $connection = $this->recordingConnection();

        $this->forgetterOn($connection, [$this->volunteer('rm_customer'), $this->volunteer('rm_invoice')])
            ->forget('subject-1');

        self::assertSame(['begin', 'commit'], $connection->calls);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_failing_volunteer_rolls_its_home_back_and_the_failure_carries_the_partial_report(): void
    {
        // the one path where an operator most needs to know the state of things: the key is already
        // destroyed, this home committed nothing, and the exception says so rather than the caller
        // having to ask again
        $connection = $this->recordingConnection();
        $forgetter = $this->forgetterOn($connection, [$this->failing('rm_customer'), $this->silent('rm_audit')]);

        try {
            $forgetter->forget('subject-1');
            self::fail('expected the forget to refuse');
        } catch (ForgetIncomplete $e) {
            self::assertSame(['begin', 'rollBack'], $connection->calls);
            self::assertNotNull($e->outcome);
            self::assertTrue($e->outcome->keyDestroyed);
            self::assertSame([], $e->outcome->touched); // nothing this home did survives its rollback
            self::assertSame(['rm_audit'], $e->outcome->untouched);
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function two_homes_each_commit_their_own_and_the_report_names_both(): void
    {
        // under a real database split the volunteers group by home, one transaction each, and the
        // report is their UNION: a home that overwrote its predecessor's work instead of joining it
        // would answer a complete forget while naming only the last one's read models
        $events = $this->recordingConnection();
        $readModels = $this->recordingConnection();
        $forgetter = new SubjectForgetter(
            $this->keys(),
            new ProjectionRegistry([$this->linkVolunteer('lk_positions'), $this->volunteer('rm_customer')]),
            new ProjectionLanes(
                new ProjectionLane($this->createStub(ProjectionStore::class), $events),
                new ProjectionLane($this->createStub(ProjectionStore::class), $readModels),
            ),
        );

        $outcome = $forgetter->forget('subject-1');

        self::assertSame(['begin', 'commit'], $events->calls);
        self::assertSame(['begin', 'commit'], $readModels->calls);
        self::assertSame(['lk_positions', 'rm_customer'], $outcome->touched);
    }

    /**
     * A link producer that volunteers a hook: its home is the EVENTS lane, which is what makes it a
     * second transaction rather than a second entry in the first.
     */
    private function linkVolunteer(string $name): LinkProjection
    {
        return new class($name) extends FakeReadModel implements ForgetsSubject, LinkProjection
        {
            public function targetStream(): StreamName
            {
                return new StreamName('lk_positions');
            }

            public function categories(): array
            {
                return [];
            }

            public function eventTypes(): array
            {
                return [];
            }

            public function forgetSubject(string $subject, Connection $tx): void {}
        };
    }

    /**
     * @param  list<ReadModel>  $projections
     */
    private function forgetterOn(Connection $connection, array $projections): SubjectForgetter
    {
        $lane = new ProjectionLane($this->createStub(ProjectionStore::class), $connection);

        return new SubjectForgetter($this->keys(), new ProjectionRegistry($projections), new ProjectionLanes($lane, $lane));
    }

    /**
     * @return Connection&object{calls: list<string>}
     */
    private function recordingConnection(): object
    {
        return new class() extends Connection
        {
            /** @var list<string> */
            public array $calls = [];

            public function __construct() {}

            public function beginTransaction(): void
            {
                $this->calls[] = 'begin';
            }

            public function commit(): void
            {
                $this->calls[] = 'commit';
            }

            public function rollBack(): void
            {
                $this->calls[] = 'rollBack';
            }
        };
    }

    /**
     * A volunteer whose hook refuses, the partial-forget path.
     */
    private function failing(string $name): ReadModel
    {
        return new class($name) extends FakeReadModel implements ForgetsSubject
        {
            public function forgetSubject(string $subject, Connection $tx): void
            {
                throw new RuntimeException('the read model refused');
            }
        };
    }

    /**
     * @param  list<ReadModel>  $projections
     */
    private function forgetter(array $projections): SubjectForgetter
    {
        $lane = new ProjectionLane($this->createStub(ProjectionStore::class), $this->createStub(Connection::class));

        return new SubjectForgetter(
            $this->keys(),
            new ProjectionRegistry($projections),
            new ProjectionLanes($lane, $lane),
        );
    }

    /**
     * @return CipherKeyStore&object{destroyed: list<string>}
     */
    private function keys(): object
    {
        return new class() implements CipherKeyStore
        {
            /** @var list<string> */
            public array $destroyed = [];

            public function destroy(string $subject): bool
            {
                $this->destroyed[] = $subject;

                return true;
            }

            public function keyFor(string $subject): ?string
            {
                return null;
            }

            public function issue(string $subject): string
            {
                return 'key';
            }

            public function isDestroyed(string $subject): bool
            {
                return in_array($subject, $this->destroyed, true);
            }
        };
    }

    /**
     * A read model that volunteers a hook, recording that it was asked and for whom.
     *
     * @return ReadModel&ForgetsSubject&object{forgotten: bool, subject: ?string}
     */
    private function volunteer(string $name): object
    {
        return new class($name) extends FakeReadModel implements ForgetsSubject
        {
            public bool $forgotten = false;

            public ?string $subject = null;

            public function forgetSubject(string $subject, Connection $tx): void
            {
                $this->forgotten = true;
                $this->subject = $subject;
            }
        };
    }

    /**
     * A read model with no hook: it keeps whatever it holds, and the report must say so.
     */
    private function silent(string $name): ReadModel
    {
        return new class($name) extends FakeReadModel {};
    }
}

abstract class FakeReadModel implements ReadModel
{
    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function apply(EventRecord $event, Connection $tx): bool
    {
        return true;
    }

    public function initialize(Connection $tx): void {}

    public function clear(Connection $tx): void {}

    public function drop(Connection $tx): void {}

    public function generation(): int
    {
        return 1;
    }
}
