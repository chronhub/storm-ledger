<?php

declare(strict_types=1);

namespace Storm\Ledger\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_count_values;
use function array_keys;
use function array_map;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function glob;
use function implode;
use function preg_match;
use function preg_quote;
use function sprintf;

/**
 * A `COLLATE "C"` pin is never a property of one column: it is a property of a COMPARISON, and it
 * only works if every side of that comparison carries it.
 *
 * PostgreSQL resolves a comparison between a pinned column and a default one to the pinned
 * collation, and an index built under the other collation can no longer serve it. Nothing reports
 * this: the query keeps returning the same rows, every test stays green, and the planner quietly
 * drops to a full scan whose cost then follows the size of the whole table rather than the batch
 * asked for. Static analysis cannot see it, a mutation gate cannot see it, and the docblock that
 * claimed the two columns matched goes on compiling.
 *
 * So the pairs are declared here and checked against the DDL. Pinning one side of a pair, or
 * renaming a column out of one, turns this red at build time instead of at profiling time.
 */
final class CollationPairingTest extends TestCase
{
    /**
     * The column groups a query compares to each other, each as `table.column`.
     *
     * A group earns its place by an actual comparison in shipped SQL, never by looking alike: two
     * columns that merely carry similar values and are never compared are free to disagree.
     *
     * @var array<string, list<string>>
     */
    private const array PAIRS = [
        // DbalSnapshotStore::staleStreams() and the orphan sweep join the two, and the byte-ordered
        // key range the sweep walks is what the pin buys in the first place
        'the snapshot sweep join' => ['stream_heads.stream', 'snapshots.stream'],

        // SagaInspectionGateway::zombieChildren() joins a child's parent id back to a correlation,
        // and DbalWorkflowInstanceStore walks the family range over the generated columns
        'the saga lineage' => [
            'workflow_instances.correlation_id',
            'workflow_instances.parent_correlation_id',
            'workflow_instances.root_correlation_id',
            'workflow_correlations.correlation_id',
        ],
    ];

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function pairs(): iterable
    {
        foreach (self::PAIRS as $name => $columns) {
            yield $name => [$name, $columns];
        }
    }

    /**
     * @param  list<string>  $columns
     */
    #[Test]
    #[DataProvider('pairs')]
    public function every_side_of_a_compared_pair_carries_the_same_collation(string $name, array $columns): void
    {
        $ddl = self::declaredDdl();
        $collations = [];

        foreach ($columns as $column) {
            $collations[$column] = self::collationOf($ddl, $column);
        }

        $distinct = array_keys(array_count_values($collations));

        self::assertCount(1, $distinct, sprintf(
            '%s: the compared columns disagree on collation (%s). A comparison resolves to the pinned side, '
            .'so the index on the default side stops serving it and the plan silently degrades to a full scan.',
            $name,
            implode(', ', array_map(static fn (string $c): string => $c.' = '.$collations[$c], $columns)),
        ));
    }

    /**
     * The declared collation of `table.column`, `default` when the DDL pins none. A pair naming a
     * table or a column the DDL no longer declares fails here, so a rename cannot leave its pair
     * behind silently.
     */
    private static function collationOf(string $ddl, string $qualified): string
    {
        [$table, $column] = explode('.', $qualified);

        $body = preg_match('/CREATE TABLE IF NOT EXISTS '.preg_quote($table, '/').'\s*\((.*?)\n\s*\)/s', $ddl, $found) === 1
            ? $found[1]
            : null;
        self::assertNotNull($body, sprintf('no CREATE TABLE declares `%s`, named by a collation pair', $table));

        $declaration = preg_match('/^\s*'.preg_quote($column, '/').'\s+([^,\n]*)/m', $body, $found) === 1
            ? $found[1]
            : null;
        self::assertNotNull($declaration, sprintf('`%s` declares no column `%s`, named by a collation pair', $table, $column));

        return preg_match('/COLLATE\s+"([A-Za-z0-9_.]+)"/', $declaration, $found) === 1 ? $found[1] : 'default';
    }

    /** Every `CREATE TABLE` the package ships, concatenated: the pairs cross module boundaries. */
    private static function declaredDdl(): string
    {
        $sources = glob(dirname(__DIR__, 2).'/*/Schema/*.php') ?: [];
        self::assertGreaterThan(5, count($sources), 'no schema class found — the glob lost its target');

        $ddl = '';
        foreach ($sources as $file) {
            $ddl .= (string) file_get_contents($file);
        }

        return $ddl;
    }
}
