<?php
namespace YayLoyalty\Database\Schema;

use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * One table's DDL. dbDelta parses CREATE TABLE with regexes, not SQL: a
 * bare `UNIQUE (cols)` installs, then silently drops on every upgrade. Every
 * definition() must use `UNIQUE KEY name (...)` / `KEY name (...)`, two
 * spaces after PRIMARY KEY, one between column and type, and state
 * ENGINE=InnoDB (savepoints need it). Installer::verify() checks the result.
 */
abstract class AbstractTableSchema {

    abstract public function slug(): string;

    abstract public function definition(): string;

    /** @return string[] */
    abstract public function expected_columns(): array;

    /**
     * The half that matters: a missing index is the likely dbDelta failure.
     *
     * @return string[]
     */
    abstract public function expected_indexes(): array;

    public function table_name(): string {
        return Table::name( $this->slug() );
    }

    public function charset_collate(): string {
        global $wpdb;

        return $wpdb->get_charset_collate();
    }
}
