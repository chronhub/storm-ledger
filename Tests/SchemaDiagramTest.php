<?php

declare(strict_types=1);

namespace Storm\Ledger\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Ledger\SchemaDiagram;

/**
 * The parsing halves of the diagram guard, pinned in isolation: the conformance test compares two
 * DERIVED lists, so a symmetric weakening of both parsers could stay green there; here each parser
 * answers alone against a fixture whose shape it cannot dodge.
 */
final class SchemaDiagramTest extends TestCase
{
    #[Test]
    public function reads_the_diagram_entities_unique_and_sorted(): void
    {
        // out of order and duplicated on purpose: dropping the sort or the dedup must show
        $puml = <<<'PUML'
            package "Saga" {
              entity "workflow_timers" as workflow_timers {
                * id : bigint <<PK>>
              }
              entity "circuit_breaker" as circuit_breaker {
                * key : text <<PK>>
              }
              entity "workflow_timers" as workflow_timers_dup <<partition>> {
                DEFAULT PARTITION
              }
            }
            PUML;

        $this->assertSame(['circuit_breaker', 'workflow_timers'], SchemaDiagram::tablesInDiagram($puml));
    }

    #[Test]
    public function reads_the_created_tables_unique_and_sorted_whatever_the_create_form(): void
    {
        // both CREATE forms, out of order, one repeated: the optional IF NOT EXISTS arm, the sort
        // and the dedup each carry an assertion of their own here
        $sql = <<<'SQL'
            CREATE TABLE IF NOT EXISTS workflow_timers (id bigint);
            CREATE TABLE circuit_breaker (key text);
            CREATE TABLE workflow_timers PARTITION OF something;
            SQL;

        $this->assertSame(['circuit_breaker', 'workflow_timers'], SchemaDiagram::tablesInSql($sql));
    }

    #[Test]
    public function an_empty_document_reads_as_an_empty_population(): void
    {
        // the guard's callers carry their own non-vacuity floor; the parser itself answers
        // honestly rather than inventing one
        $this->assertSame([], SchemaDiagram::tablesInDiagram(''));
        $this->assertSame([], SchemaDiagram::tablesInSql('-- no tables here'));
    }
}
