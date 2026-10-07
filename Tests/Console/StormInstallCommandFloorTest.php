<?php

declare(strict_types=1);

namespace Storm\Ledger\Tests\Console;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\AggregateRepository\Schema\SnapshotSchema;
use Storm\Chronicler\Schema\EventStoreHighWaterSchema;
use Storm\Chronicler\Schema\EventStoreSchema;
use Storm\Chronicler\Schema\IdempotencyRegistrySchema;
use Storm\Chronicler\Schema\InboxSchema;
use Storm\Chronicler\Schema\OutboxArchiveSchema;
use Storm\Chronicler\Schema\OutboxSchema;
use Storm\Chronicler\Schema\StreamHeadsSchema;
use Storm\EventLinks\Schema\EventLinkSchema;
use Storm\EventLinks\Schema\EventLinkStreamSchema;
use Storm\Ledger\Console\StormInstallCommand;
use Storm\Ledger\Schema\CryptoKeySchema;
use Storm\Projector\Schema\ProjectionSchema;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

use function preg_replace;

/**
 * The version floor, judged without a server: identity on a partitioned table is PostgreSQL 17,
 * so an older one is refused BEFORE any DDL runs rather than discovered mid-install. The reading
 * comes from a single scalar probe, which is why this needs no database of its own; every other
 * install guarantee is proven against a real one.
 *
 * @see \Storm\Tests\Integration\Ledger\StormInstallCommandTest
 */
final class StormInstallCommandFloorTest extends TestCase
{
    #[Test]
    public function refuses_a_server_below_the_schema_floor_before_touching_anything(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'db' => 'app', 'schema' => 'public', 'version' => '16.4', 'version_num' => 160_000,
        ]);
        $connection->expects($this->never())->method('executeStatement');

        $tester = new CommandTester(new StormInstallCommand($connection));
        $code = $tester->execute([]);

        // the console wraps its block at the terminal width, so the sentence is judged unwrapped
        $refusal = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('PostgreSQL 17 is the schema floor', $refusal);
        $this->assertStringContainsString('the events side runs 16.4', $refusal, 'the refusal names the side and what it found');
    }

    #[Test]
    public function refuses_drop_and_reset_together(): void
    {
        $connection = $this->createStub(Connection::class);
        $tester = new CommandTester(new StormInstallCommand($connection));
        $code = $tester->execute(['--drop' => true, '--reset' => true]);

        $this->assertSame(Command::INVALID, $code);
        $this->assertStringContainsString('--drop and --reset are mutually exclusive', $tester->getDisplay());
    }

    #[Test]
    public function refuses_drop_without_force_in_non_interactive_session_at_floor_version(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'db' => 'app', 'schema' => 'public', 'version' => '17.0', 'version_num' => 170_000,
        ]);

        $tester = new CommandTester(new StormInstallCommand($connection));
        $code = $tester->execute(['--drop' => true], ['interactive' => false]);

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertSame(Command::INVALID, $code);
        $this->assertStringContainsString('events', $display);
        $this->assertStringContainsString('PostgreSQL 17.0', $display);
        $this->assertStringContainsString('--drop destroys the event store (the source of truth) on app/public and the session is non-interactive', $display);
    }

    #[Test]
    public function refuses_reset_without_force_in_non_interactive_session(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'db' => 'app', 'schema' => 'public', 'version' => '18.1', 'version_num' => 180_001,
        ]);

        $tester = new CommandTester(new StormInstallCommand($connection));
        $code = $tester->execute(['--reset' => true], ['interactive' => false]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::INVALID, $code);
        $this->assertStringContainsString('--reset destroys the event store', $display);
    }

    #[Test]
    public function neither_flag_set_never_reaches_the_destructive_prompt(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'db' => 'app', 'schema' => 'public', 'version' => '17.0', 'version_num' => 170_000,
        ]);

        $tester = new CommandTester(new StormInstallCommand($connection));

        try {
            $tester->execute([], ['interactive' => false]);
        } catch (Throwable) {
            // the DDL/conformance machinery past this guard is proven elsewhere against a real
            // database; reaching past the guard at all, whatever happens next, is what this proves
        }

        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('destroys the event store', $display);
        $this->assertStringNotContainsString('mutually exclusive', $display);
    }

    #[Test]
    public function neither_flag_installs_every_schemas_full_up_statement_list_in_order(): void
    {
        // dropping, reordering, or collapsing a spread would still return SOME list of the right
        // element count only by luck; comparing the exact statements executed to calling every
        // schema's up() directly is the only way ArrayItemRemoval and SpreadOneItem cannot hide
        $expected = [
            ...EventStoreSchema::up(),
            ...EventStoreHighWaterSchema::up(),
            ...StreamHeadsSchema::up(),
            ...OutboxSchema::up(),
            ...OutboxArchiveSchema::up(),
            ...InboxSchema::up(),
            ...IdempotencyRegistrySchema::up(),
            ...ProjectionSchema::up(),
            ...EventLinkSchema::up(),
            ...EventLinkStreamSchema::up(),
            ...SnapshotSchema::up(),
            ...CryptoKeySchema::up(),
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'db' => 'app', 'schema' => 'public', 'version' => '17.0', 'version_num' => 170_000,
        ]);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->method('isTransactionActive')->willReturn(true);
        $sawLockTimeout = false;
        $connection->method('fetchOne')->willReturnCallback(function (string $sql) use (&$sawLockTimeout): int {
            $sawLockTimeout = $sawLockTimeout || str_contains($sql, 'pg_try_advisory_xact_lock');

            return str_contains($sql, 'pg_try_advisory_xact_lock') ? 1 : 0;
        });
        $executed = [];
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$executed): int {
            // InstallLock's own SET LOCAL precedes the schema DDL; irrelevant to the statement list
            // this test proves, so it is filtered rather than folded into $expected
            if (! str_starts_with($sql, 'SET LOCAL lock_timeout')) {
                $executed[] = $sql;
            }

            return 0;
        });

        $tester = new CommandTester(new StormInstallCommand($connection));
        $tester->execute([], ['interactive' => false]);

        $this->assertTrue($sawLockTimeout, 'InstallLock::acquire must run before the DDL, or a concurrent install could interleave');
        $this->assertSame($expected, $executed);
    }

    #[Test]
    public function drop_alone_runs_every_schemas_down_statement_and_none_of_up(): void
    {
        // pins the OTHER side of the ($drop || $reset) ternaries: drop alone must select down()
        // and the empty up(), the mirror of the no-flag case above selecting up() and the empty down()
        $expected = [
            ...CryptoKeySchema::down(),
            ...SnapshotSchema::down(),
            ...EventLinkStreamSchema::down(),
            ...EventLinkSchema::down(),
            ...ProjectionSchema::down(),
            ...IdempotencyRegistrySchema::down(),
            ...InboxSchema::down(),
            ...OutboxArchiveSchema::down(),
            ...OutboxSchema::down(),
            ...StreamHeadsSchema::down(),
            ...EventStoreHighWaterSchema::down(),
            ...EventStoreSchema::down(),
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'db' => 'app', 'schema' => 'public', 'version' => '17.0', 'version_num' => 170_000,
        ]);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->method('isTransactionActive')->willReturn(true);
        $sawLockTimeout = false;
        $connection->method('fetchOne')->willReturnCallback(function (string $sql) use (&$sawLockTimeout): int {
            $sawLockTimeout = $sawLockTimeout || str_contains($sql, 'pg_try_advisory_xact_lock');

            return 1;
        });
        $executed = [];
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$executed): int {
            if (! str_starts_with($sql, 'SET LOCAL lock_timeout')) {
                $executed[] = $sql;
            }

            return 0;
        });

        $tester = new CommandTester(new StormInstallCommand($connection));
        $tester->execute(['--drop' => true, '--force' => true], ['interactive' => false]);

        $this->assertTrue($sawLockTimeout, 'InstallLock::acquire must run before the DDL, or a concurrent install could interleave');
        $this->assertSame($expected, $executed);
    }

    #[Test]
    public function a_ddl_failure_rolls_back_and_lets_the_original_exception_escape(): void
    {
        // the failing statement must be identifiable from the rethrown exception alone, and the
        // transaction must not survive it: a silently swallowed throw or a skipped rollback would
        // leave a half-applied side committed the next time nothing here catches it
        $failure = new RuntimeException('DDL exploded');

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'db' => 'app', 'schema' => 'public', 'version' => '17.0', 'version_num' => 170_000,
        ]);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('fetchOne')->willReturn(1);
        $connection->method('executeStatement')->willThrowException($failure);
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('commit');

        $caught = null;

        try {
            new CommandTester(new StormInstallCommand($connection))->execute(['--drop' => true, '--force' => true], ['interactive' => false]);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertSame($failure, $caught, 'the original exception must propagate, not be swallowed');
    }

    #[Test]
    public function reports_both_homes_and_refuses_when_read_models_side_is_below_floor(): void
    {
        $eventsConn = $this->createStub(Connection::class);
        $eventsConn->method('fetchAssociative')->willReturn([
            'db' => 'app_events', 'schema' => 'public', 'version' => '18.1', 'version_num' => 180_001,
        ]);

        $readConn = $this->createStub(Connection::class);
        $readConn->method('fetchAssociative')->willReturn([
            'db' => 'app_reads', 'schema' => 'read_schema', 'version' => '16.2', 'version_num' => 160_002,
        ]);

        $tester = new CommandTester(new StormInstallCommand($eventsConn, $readConn));
        $code = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('events', $display);
        $this->assertStringContainsString('read-models', $display);
        $this->assertStringContainsString('app_reads / read_schema', $display);
        $this->assertStringContainsString('the read-models side runs 16.2', $display);
    }
}
