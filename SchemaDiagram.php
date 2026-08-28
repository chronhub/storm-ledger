<?php

declare(strict_types=1);

namespace Storm\Ledger;

use function array_unique;
use function preg_match_all;
use function sort;

/**
 * The mechanical half of the schema diagram's guard. `docs/schema.puml` is maintained by hand,
 * since its module grouping, its two-store split and its dashed logical associations are semantic
 * content no generator can derive, and a hand-maintained reference rots silently without a gate.
 * This class derives the TABLE population from each side so `make ddl` and the packaging suite can
 * compare them; the columns and the associations stay reviewed by eyes, which the guard's callers
 * say instead of implying more.
 */
final readonly class SchemaDiagram
{
    /**
     * @return list<string> the entity names the diagram declares, unique and sorted
     */
    public static function tablesInDiagram(string $puml): array
    {
        preg_match_all('/entity "([a-z0-9_]+)"/', $puml, $matches);

        // sort() reindexes, so the dedup needs no re-keying of its own
        $tables = array_unique($matches[1]);
        sort($tables);

        return $tables;
    }

    /**
     * @return list<string> the table names the rendered DDL creates, unique and sorted
     */
    public static function tablesInSql(string $sql): array
    {
        preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?([a-z0-9_]+)/', $sql, $matches);

        // sort() reindexes, so the dedup needs no re-keying of its own
        $tables = array_unique($matches[1]);
        sort($tables);

        return $tables;
    }
}
