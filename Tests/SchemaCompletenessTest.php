<?php

declare(strict_types=1);

namespace Storm\Ledger\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Storm\AggregateRepository\Schema\SnapshotSchema;
use Storm\Chronicler\Schema\EventStoreSchema;
use Storm\EventLinks\Schema\EventLinkSchema;
use Storm\Ledger\Schema\CryptoKeySchema;
use Storm\Ledger\SchemaConformance;
use Storm\Projector\Schema\ProjectionSchema;

/**
 * Pins the lists that can silently drift apart: the `*Schema` classes the core packages declare,
 * the conformance map `storm:install` verifies against the catalogs, and each class's `up()` /
 * `down()` symmetry. The schema classes are discovered from the FILESYSTEM, so a ninth one is
 * seen without anyone remembering to look. A schema class gaining a table, a column, a constraint
 * or an index without {@see SchemaConformance} noticing turns this red; the conformance map failing
 * live turns the install integration test red too, so the chain closes end to end.
 */
final class SchemaCompletenessTest extends TestCase
{
    /** One known class per core package; its file location anchors the package's Schema directory. */
    private const array ANCHORS = [EventStoreSchema::class, EventLinkSchema::class, ProjectionSchema::class, SnapshotSchema::class, CryptoKeySchema::class];

    #[Test]
    public function every_declared_table_is_in_the_conformance_map_and_nothing_more(): void
    {
        $declared = [];
        foreach ($this->schemaClasses() as $class) {
            $declared = [...$declared, ...$this->createdTables($class)];
        }
        sort($declared);

        $mapped = array_keys(SchemaConformance::COLUMNS);
        sort($mapped);

        self::assertSame($declared, $mapped, 'a core *Schema class and SchemaConformance::COLUMNS drifted apart — storm:install would not verify what the packages declare');
    }

    #[Test]
    public function conformance_columns_match_the_declared_ddl_exactly(): void
    {
        // Tables and their names are pinned above; this pins the COLUMN lists. Without it, a Schema
        // class gaining a column while SchemaConformance::COLUMNS stays behind fails no test, and
        // the probe then blesses a pre-existing table missing that column, recreating exactly the
        // workflow_history blindness: an install reported verified, an INSERT failing later.
        $declared = $this->declaredColumnsByTable();

        foreach (SchemaConformance::COLUMNS as $table => $columns) {
            self::assertArrayHasKey($table, $declared, sprintf('%s is in SchemaConformance::COLUMNS but no core *Schema class declares it', $table));

            self::assertSame(
                [],
                array_values(array_diff($declared[$table], array_keys($columns))),
                sprintf('%s: column(s) declared by the DDL but absent from SchemaConformance::COLUMNS — the probe would bless a pre-existing table missing them', $table),
            );
            self::assertSame(
                [],
                array_values(array_diff(array_keys($columns), $declared[$table])),
                sprintf('%s: column(s) in SchemaConformance::COLUMNS that the DDL no longer declares — the map drifted away from the schema', $table),
            );
        }
    }

    #[Test]
    public function every_schema_class_drops_what_it_creates(): void
    {
        foreach ($this->schemaClasses() as $class) {
            $dropped = $this->droppedTables($class);
            foreach (array_diff($this->createdTables($class), $this->partitionTables($class)) as $table) {
                self::assertContains(
                    $table,
                    $dropped,
                    sprintf('%s creates %s but its down() never drops it (partitions ride with their parent, everything else must be symmetric)', $class, $table),
                );
            }
        }
    }

    #[Test]
    public function conformance_constraints_indexes_and_partitioning_exist_in_the_declared_ddl(): void
    {
        $ddl = [];
        foreach ($this->schemaClasses() as $class) {
            $sql = implode("\n", $this->statements($class, 'up'));
            foreach ($this->createdTables($class) as $table) {
                $ddl[$table] = $sql;
            }
        }

        foreach (SchemaConformance::CONSTRAINTS as $table => $constraints) {
            foreach ($constraints as $name => $fragment) {
                self::assertStringContainsString('CONSTRAINT '.$name, $ddl[$table], sprintf('constraint %s is verified by SchemaConformance but no longer declared for %s', $name, $table));
                if ($fragment !== null) {
                    // a declared needle must appear in the rendered DDL, a complete one in its declared
                    // form: the probe compares it against pg_get_constraintdef, so a needle the DDL no
                    // longer contains means either the map drifted or it was never deparse-stable
                    self::assertStringContainsString(self::declaredForm($fragment), $ddl[$table], sprintf("constraint %s: required fragment '%s' is not in the declared DDL for %s — the conformance map drifted away from the schema", $name, $fragment, $table));
                }
            }
        }
        foreach (SchemaConformance::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                self::assertStringContainsString('IF NOT EXISTS '.$name, $ddl[$table], sprintf('index %s is verified by SchemaConformance but no longer declared for %s', $name, $table));
            }
        }
        foreach (SchemaConformance::PARTITIONED as $table) {
            self::assertStringContainsString('PARTITION BY', $ddl[$table], sprintf('%s is expected partitioned but its DDL no longer declares PARTITION BY', $table));
        }
    }

    #[Test]
    public function every_declared_constraint_and_index_is_verified_by_the_conformance_map(): void
    {
        // the OTHER direction, the one the test above cannot see: a schema class gaining a CHECK or
        // an index that the map never learns about is a shape nobody proves, and the install still
        // reports verified over a pre-existing table that may not carry it at all
        $verified = [];
        foreach ([SchemaConformance::CONSTRAINTS, SchemaConformance::INDEXES] as $catalog) {
            foreach ($catalog as $entries) {
                $verified = [...$verified, ...array_keys($entries)];
            }
        }

        foreach ($this->schemaClasses() as $class) {
            $sql = implode("\n", $this->statements($class, 'up'));
            preg_match_all('/(?:CONSTRAINT|INDEX IF NOT EXISTS) ([a-z_]+)/', $sql, $matches);

            foreach ($matches[1] as $name) {
                self::assertContains($name, $verified, sprintf('%s declares %s, and SchemaConformance verifies no such name — storm:install would never prove it', $class, $name));
            }
        }
    }

    /**
     * @return list<class-string>
     */
    private function schemaClasses(): array
    {
        $classes = [];
        foreach (self::ANCHORS as $anchor) {
            $reflection = new ReflectionClass($anchor);
            $namespace = $reflection->getNamespaceName();
            foreach (glob(dirname((string) $reflection->getFileName()).'/*.php') ?: [] as $file) {
                $class = $namespace.'\\'.basename($file, '.php');
                self::assertTrue(class_exists($class), sprintf('%s does not autoload — a stray file in a Schema directory?', $class));
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Every schema class exposes static `up()` / `down()` statement lists; the convention this
     * suite pins for any NEW class a package grows.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private function statements(string $class, string $method): array
    {
        $callable = [$class, $method];
        self::assertIsCallable($callable, sprintf('%s::%s() — a Schema class must expose the static statement list', $class, $method));

        /** @var list<string> */
        return $callable();
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private function createdTables(string $class): array
    {
        preg_match_all('/CREATE (?:UNLOGGED )?TABLE IF NOT EXISTS ([a-z_]+)/', implode("\n", $this->statements($class, 'up')), $matches);

        return $matches[1];
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private function partitionTables(string $class): array
    {
        preg_match_all('/IF NOT EXISTS ([a-z_]+) PARTITION OF/', implode("\n", $this->statements($class, 'up')), $matches);

        return $matches[1];
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private function droppedTables(string $class): array
    {
        preg_match_all('/DROP TABLE IF EXISTS ([a-z_]+)/', implode("\n", $this->statements($class, 'down')), $matches);

        return $matches[1];
    }

    /**
     * Column names per table, parsed from every `up()` CREATE TABLE body. A `PARTITION OF` child
     * declares no columns of its own and inherits its parent's list, exactly as PostgreSQL does.
     *
     * @return array<string, list<string>>
     */
    private function declaredColumnsByTable(): array
    {
        $columns = [];
        $partitions = [];

        foreach ($this->schemaClasses() as $class) {
            foreach ($this->statements($class, 'up') as $statement) {
                if (preg_match('/CREATE (?:UNLOGGED )?TABLE IF NOT EXISTS ([a-z_]+) PARTITION OF ([a-z_]+)/', $statement, $matches) === 1) {
                    $partitions[$matches[1]] = $matches[2];

                    continue;
                }
                if (preg_match('/CREATE (?:UNLOGGED )?TABLE IF NOT EXISTS ([a-z_]+)\s*\(/', $statement, $matches) === 1) {
                    $columns[$matches[1]] = $this->columnsOf($statement);
                }
            }
        }

        foreach ($partitions as $child => $parent) {
            self::assertArrayHasKey($parent, $columns, sprintf('%s is PARTITION OF %s but no CREATE TABLE declares the parent', $child, $parent));
            $columns[$child] = $columns[$parent];
        }

        return $columns;
    }

    /**
     * The column names of one CREATE TABLE statement, read line by line: the house DDL format puts
     * one column per line, `CONSTRAINT` and `--` comment lines aside, and closes the body on its own
     * `)` line. Deliberately NOT an SQL parser: this suite pins conventions, and a statement this
     * parse misreads is a statement breaking the format every schema follows.
     *
     * @return list<string>
     */
    private function columnsOf(string $statement): array
    {
        $columns = [];
        foreach (array_slice(explode("\n", $statement), 1) as $line) {
            $line = trim($line);
            if (str_starts_with($line, ')')) {
                break;
            }
            if (str_starts_with($line, '--') || str_starts_with($line, 'CONSTRAINT ')) {
                continue;
            }
            if (preg_match('/^([a-z_]+)/', $line, $matches) === 1) {
                $columns[] = $matches[1];
            }
        }

        self::assertNotSame([], $columns, 'no columns parsed from a CREATE TABLE body — the DDL no longer follows the one-column-per-line format this suite relies on');

        return $columns;
    }

    // a complete `CHECK` needle IS the deparse, which wraps the expression in one more pair of
    // parentheses than the declared DDL writes: the DDL carries it with that pair removed
    private static function declaredForm(string $needle): string
    {
        return str_starts_with($needle, 'CHECK ((') && str_ends_with($needle, '))') ? 'CHECK '.substr($needle, 7, -1) : $needle;
    }
}
