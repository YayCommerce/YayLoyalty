<?php
namespace YayLoyalty\Database;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Database\Schema\AbstractTableSchema;
use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Helpers\SetupHelper;
use YayLoyalty\Utils\Log;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's tables. maybe_upgrade() runs on
 * plugins_loaded (admin_init misses REST, cron and WP-CLI) outside the
 * WooCommerce guard, under a mutex so two requests cannot both seed
 * built-ins. verify() checks indexes because dbDelta fails silently on them.
 */
final class Installer {

    public const OPTION_DB_VERSION = 'yayloyalty_db_version';

    private const LOCK_KEY = 'yayloyalty_upgrading';

    /** Long enough for a slow dbDelta, short enough to self-heal. */
    private const LOCK_TTL = 60;

    /**
     * Registered table schemas. The yayloyalty_table_schemas filter can add more.
     *
     * @return AbstractTableSchema[]
     */
    public static function schemas(): array {
        $schemas = [
            new \YayLoyalty\Database\Schema\CustomersTable(),
            new \YayLoyalty\Database\Schema\LedgerTable(),
            new \YayLoyalty\Database\Schema\CampaignsTable(),
            new \YayLoyalty\Database\Schema\RewardsTable(),
            new \YayLoyalty\Database\Schema\CustomerMetaTable(),
        ];

        /**
         * Table schemas to install.
         *
         * @param AbstractTableSchema[] $schemas
         */
        return apply_filters( 'yayloyalty_table_schemas', $schemas );
    }

    /** Runs dbDelta for every schema, seeds built-ins, then stamps the version. Idempotent. */
    public static function install(): void {
        // Read before anything else: only a site that never stamped a version is a fresh install.
        $is_fresh = false === get_option( self::OPTION_DB_VERSION );

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ( self::schemas() as $schema ) {
            dbDelta( $schema->definition() );
        }

        self::seed_builtins();

        // Not only on activation: shop_manager does not exist until WooCommerce has been activated once.
        Capabilities::install();

        self::run_migrations();

        self::verify();

        SetupHelper::on_install( $is_fresh );

        // Stamped last so a crash mid-install retries on the next request.
        update_option( self::OPTION_DB_VERSION, YAYLOYALTY_VERSION );
    }

    /** Installs or upgrades when the stored version differs from the plugin's. Hooked on plugins_loaded. */
    public static function maybe_upgrade(): void {
        if ( get_option( self::OPTION_DB_VERSION ) === YAYLOYALTY_VERSION ) {
            return;
        }

        if ( ! self::acquire_lock() ) {
            // Another request is upgrading. Do not queue behind it.
            return;
        }

        try {
            // Re-check inside the lock: the previous holder may have just finished.
            if ( get_option( self::OPTION_DB_VERSION ) !== YAYLOYALTY_VERSION ) {
                self::install();
            }
        } finally {
            self::release_lock();
        }
    }

    /** Seeds every built-in row. Idempotent by slug, with the unique key on slug as the backstop. */
    private static function seed_builtins(): void {
        self::seed_builtin_campaigns();
        self::seed_builtin_reward();
    }

    /** Seeds the built-in campaigns. Addressed by slug: ids differ between sites. */
    private static function seed_builtin_campaigns(): void {
        $repository = new \YayLoyalty\Repositories\CampaignRepository();
        $now        = \YayLoyalty\Utils\Dates::now();

        $builtins = [
            'default-purchase' => [
                'name'    => __( 'Points for purchases', 'yayloyalty' ),
                'trigger' => \YayLoyalty\Enums\CampaignTrigger::PURCHASE,
            ],
            \YayLoyalty\Repositories\CampaignRepository::SIGNUP_SLUG => [
                'name'    => __( 'Points for signing up', 'yayloyalty' ),
                'trigger' => \YayLoyalty\Enums\CampaignTrigger::SIGNUP,
            ],
            \YayLoyalty\Repositories\CampaignRepository::REVIEW_SLUG => [
                'name'    => __( 'Points for writing a review', 'yayloyalty' ),
                'trigger' => \YayLoyalty\Enums\CampaignTrigger::REVIEW,
            ],
        ];

        foreach ( $builtins as $slug => $spec ) {
            $repository->seed_builtin(
                new \YayLoyalty\Models\Campaign(
                    null,
                    $spec['name'],
                    $slug,
                    $spec['trigger'],
                    \YayLoyalty\Enums\Status::ACTIVE,
                    // Priority 0: any merchant campaign outranks the built-in.
                    0,
                    \YayLoyalty\Models\Campaign::default_config( $spec['trigger'] ),
                    [],
                    null,
                    null,
                    true,
                    null,
                    $now,
                    $now
                )
            );
        }
    }

    private static function seed_builtin_reward(): void {
        $repository = new \YayLoyalty\Repositories\RewardRepository();
        $now        = \YayLoyalty\Utils\Dates::now();
        $type       = \YayLoyalty\Enums\RewardType::FLEXIBLE_DISCOUNT;

        $repository->seed_builtin(
            new \YayLoyalty\Models\Reward(
                null,
                __( 'Cart discount', 'yayloyalty' ),
                \YayLoyalty\Repositories\RewardRepository::CART_DISCOUNT_SLUG,
                $type,
                \YayLoyalty\Enums\Status::ACTIVE,
                0,
                // cost_points null: flexible_discount is the slider type.
                null,
                \YayLoyalty\Models\Reward::default_config( $type ),
                [],
                null,
                null,
                true,
                null,
                null,
                $now,
                $now
            )
        );
    }

    /** Data migrations dbDelta cannot express: it adds columns and indexes but never drops them. */
    private static function run_migrations(): void {
        self::drop_index_if_exists( 'ledger', 'uniq_ledger_event' );
        // idx_user_history_type covers both, and dbDelta has already added it by the time this runs.
        self::drop_index_if_exists( 'ledger', 'idx_user_history' );
        self::drop_index_if_exists( 'ledger', 'idx_user_type' );
        self::rename_customer_meta_key( 'account_created', ActivityEvent::MEMBERSHIP_STARTED );

        /** Fires after the tables are created or updated, and before the version stamp. */
        do_action( 'yayloyalty_run_migrations' );
    }

    /**
     * Renames an activity `event_type` in rows already written. A no-op once no row has the old key,
     * so every upgrade may call it.
     */
    private static function rename_customer_meta_key( string $old_key, string $new_key ): void {
        global $wpdb;

        $table = Table::name( 'customer_meta' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-off data migration on our own table; there is nothing to cache.
        if ( false === $wpdb->update( $table, [ 'meta_key' => $new_key ], [ 'meta_key' => $old_key ], [ '%s' ], [ '%s' ] ) ) {
            Log::warning( 'Installer', sprintf( 'could not rename meta_key %s to %s on %s: %s', $old_key, $new_key, $table, $wpdb->last_error ) );
        }
    }

    /**
     * Drops a superseded index. It is a no-op when the index is already gone, so every upgrade may call it.
     * Use it only for an index nothing relies on for idempotency: the ledger's guard is uniq_idempotency_key.
     */
    private static function drop_index_if_exists( string $table_slug, string $index ): void {
        global $wpdb;

        $table = esc_sql( Table::name( $table_slug ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the table name comes from Table::name(); the index name is bound or backtick-quoted from a literal.
        $present = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index ) );

        if ( null === $present ) {
            return;
        }

        // An upgrade must finish. A leftover index costs a little write speed, never correctness.
        if ( false === $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `" . esc_sql( $index ) . '`' ) ) {
            Log::warning( 'Installer', sprintf( 'could not drop index %s on %s: %s', $index, $table, $wpdb->last_error ) );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Confirms dbDelta applied each schema. dbDelta reports success even when it skipped an index it could not parse.
     *
     * @return array<string, string[]> Problems found, keyed by table name.
     */
    public static function verify(): array {
        global $wpdb;

        $problems = [];

        foreach ( self::schemas() as $schema ) {
            $table  = $schema->table_name();
            $issues = [];

            $columns = $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB -- a schema check on our own escaped table name; it takes no values and must read live.
            $columns = is_array( $columns ) ? $columns : [];

            foreach ( $schema->expected_columns() as $column ) {
                if ( ! in_array( $column, $columns, true ) ) {
                    $issues[] = 'missing column: ' . $column;
                }
            }

            $index_rows  = $wpdb->get_results( 'SHOW INDEX FROM `' . esc_sql( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB -- a schema check on our own escaped table name; it takes no values and must read live.
            $index_names = [];

            if ( is_array( $index_rows ) ) {
                foreach ( $index_rows as $row ) {
                    if ( isset( $row['Key_name'] ) ) {
                        $index_names[] = $row['Key_name'];
                    }
                }
            }

            foreach ( $schema->expected_indexes() as $index ) {
                if ( ! in_array( $index, $index_names, true ) ) {
                    $issues[] = 'missing index: ' . $index;
                }
            }

            if ( ! empty( $issues ) ) {
                $problems[ $table ] = $issues;
                self::log( $table . ' — ' . implode( '; ', $issues ) );
            }
        }

        return $problems;
    }

    /** Acquires the upgrade mutex via add_option(), which fails when the row exists. Transients are not atomic under an object cache. */
    private static function acquire_lock(): bool {
        $acquired = add_option( self::LOCK_KEY, time(), '', 'no' );

        if ( $acquired ) {
            return true;
        }

        // Reclaim a lock abandoned by a fatal mid-upgrade.
        $held_since = (int) get_option( self::LOCK_KEY, 0 );

        if ( $held_since > 0 && ( time() - $held_since ) > self::LOCK_TTL ) {
            self::log( 'reclaiming stale upgrade lock held since ' . $held_since );
            update_option( self::LOCK_KEY, time(), 'no' );

            return true;
        }

        return false;
    }

    private static function release_lock(): void {
        delete_option( self::LOCK_KEY );
    }

    /** Error level, not debug: a failed table create must be recorded with WP_DEBUG off. */
    private static function log( string $message ): void {
        Log::error( 'Installer', $message );
    }
}
