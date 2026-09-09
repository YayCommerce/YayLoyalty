<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * One table's DDL, owned by the table itself.
 *
 * IMPORTANT — dbDelta index grammar is not SQL grammar.
 *
 * dbDelta parses CREATE TABLE with regular expressions, not a SQL parser, and
 * only recognises the KEY forms. A bare `UNIQUE (a, b)` succeeds on the first
 * CREATE and then fails to normalise on every subsequent upgrade run, silently
 * leaving the index off. The ledger's idempotency guarantee depends on its
 * unique key surviving upgrades, not just installs — so getting this wrong
 * breaks the invariant on the one code path nobody tests.
 *
 * Every definition() MUST follow all of these:
 *   - `UNIQUE KEY name (cols)` — never bare `UNIQUE (cols)`
 *   - `KEY name (cols)` — never `INDEX`
 *   - every key explicitly named
 *   - two spaces after `PRIMARY KEY`
 *   - one space between a column name and its type
 *   - `ENGINE=InnoDB` stated explicitly (savepoints do not exist on MyISAM)
 *
 * Implementations must also declare expected_columns() and expected_indexes()
 * so Installer::verify() can prove dbDelta actually applied them.
 */
abstract class AbstractTableSchema {

    /**
     * Table slug, without prefix. Example: 'ledger'.
     */
    abstract public function slug(): string;

    /**
     * Full CREATE TABLE statement for dbDelta. See the grammar rules above.
     */
    abstract public function definition(): string;

    /**
     * Column names this table must have after install, for verify().
     *
     * @return string[]
     */
    abstract public function expected_columns(): array;

    /**
     * Index names this table must have after install, for verify().
     *
     * This is the half that matters: dbDelta fails silently on index grammar,
     * so a missing index is the likely failure, not a missing column.
     *
     * @return string[]
     */
    abstract public function expected_indexes(): array;

    /**
     * Fully-qualified table name.
     *
     * Never memoize this. Multisite activation loops switch_to_blog(), and a
     * cached prefix would install every blog's tables into the first blog.
     */
    public function table_name(): string {
        global $wpdb;

        return $wpdb->prefix . 'yayloyalty_' . $this->slug();
    }

    /**
     * Charset/collation clause for the CREATE TABLE statement.
     */
    public function charset_collate(): string {
        global $wpdb;

        return $wpdb->get_charset_collate();
    }
}
