<?php

declare(strict_types=1);

namespace Storm\Ledger;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function class_exists;
use function constant;
use function defined;
use function implode;
use function method_exists;
use function preg_match;
use function rtrim;
use function sort;
use function str_replace;
use function substr;
use function trim;

/**
 * Renders every Storm `*Schema::up()` into one SQL document, the source of `docs/schema.sql`.
 *
 * That file is the repo's global SQL reference: browse it to see every table `storm:install` creates, or
 * point an IDE DDL data source at it. Schemas are discovered from the FILESYSTEM, so a new one appears
 * without anyone remembering to list it, the same discovery `SchemaCompletenessTest` relies on.
 *
 * A CLASS rather than a script body, so the rendering is callable twice: `bin/dump-ddl.php` writes it,
 * and `SchemaDumpTest` renders it in memory and compares against the tracked file. Without that second
 * caller the document drifts from the schemas silently, and a generated file nothing verifies is just
 * a file that used to be true.
 *
 * The dump is SPLIT-AWARE: a schema declaring `CONNECTION_SIDE = 'read_model_store'` follows
 * `storm.connections.read_model_store` when the app opts into a dedicated read-model database;
 * everything else lives with the events. The side is declared ON the schema class, self-describing like
 * the discovery, never in a hand-kept list here; `event_links` is the cautionary tale, Projector-owned
 * yet events-side, because the derived-stream read JOINs it with `event_store`.
 *
 * @see SchemaConformance the sibling projection of the same schemas, for verification rather than docs
 */
final readonly class SchemaDump
{
    private const string HEADER = <<<'TXT'
        -- storm DDL — generated from every Storm *Schema::up().
        -- Regenerate after a schema change:  make ddl   —   DO NOT EDIT BY HAND.
        -- Purpose: the global SQL reference for this repo — browse it to see every table, or point an IDE DDL data source at it.

        TXT;

    private const string EVENTS_BANNER = <<<'TXT'
        -- ============================================================================
        -- EVENTS SIDE — always on the DEFAULT connection: the event store and every
        -- transactional table. The safe-head watermark lives here, next to the events.
        -- Note event_links: Projector-owned yet EVENTS-side — the derived-stream read
        -- JOINS it with event_store, the two must share a database.
        -- ============================================================================
        TXT;

    private const string STORE_BANNER = <<<'TXT'
        -- ============================================================================
        -- READ-MODEL STORE SIDE — schemas declaring CONNECTION_SIDE='read_model_store'.
        -- Follows `storm.connections.read_model_store` when the app opts into a
        -- dedicated read-model database (default: same database as the events). The
        -- app's rm_* tables belong on this side too — created by the app, not storm.
        -- ============================================================================
        TXT;

    /**
     * The complete document, byte-for-byte what `docs/schema.sql` must contain.
     *
     * @param  string  $srcRoot  the `src/` directory to discover schemas under
     */
    public static function render(string $srcRoot): string
    {
        $events = [];
        $store = [];

        foreach (self::discover($srcRoot) as $class) {
            $block = "\n-- ".$class."\n";
            foreach ($class::up() as $statement) {
                $block .= rtrim(trim($statement), ';').";\n";
            }

            $isStoreSide = defined($class.'::CONNECTION_SIDE') && constant($class.'::CONNECTION_SIDE') === 'read_model_store';
            $isStoreSide ? $store[] = $block : $events[] = $block;
        }

        return self::HEADER
            ."\n".self::EVENTS_BANNER."\n".implode('', $events)
            ."\n".self::STORE_BANNER."\n".implode('', $store);
    }

    /**
     * Every `*Schema` class under `$srcRoot` that exposes an `up()`, name-ordered so the document is
     * stable across filesystems.
     *
     * @return list<class-string>
     */
    public static function discover(string $srcRoot): array
    {
        $files = [];
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS));

        foreach ($items as $file) {
            if ($file->isFile() && preg_match('#/Schema/[A-Za-z0-9]+Schema\.php$#', $file->getPathname()) === 1) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $classes = [];
        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'Storm\\'.str_replace('/', '\\', substr($file, strlen($srcRoot) + 1, -4));

            if (class_exists($class) && method_exists($class, 'up')) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
