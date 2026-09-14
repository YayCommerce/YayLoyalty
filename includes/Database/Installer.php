<?php
namespace YayLoyalty\Database;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Database\Schema\AbstractTableSchema;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades this plugin's tables.
 *
 * Three things here exist because of specific failure modes:
 *
 * 1. maybe_upgrade() runs on plugins_loaded, not admin_init. admin_init fires
 *    only for admin pages, admin-ajax and admin-post — it misses REST, cron,
 *    front-end and WP-CLI entirely, so a headless or REST-driven store would
 *    never migrate after an update. It also must sit outside the WooCommerce
 *    guard, or a site with WooCommerce inactive never migrates either.
 *
 * 2. A mutex wraps the upgrade. The version is stamped last, so without one an
 *    admin page load and a concurrent heartbeat both run dbDelta and both seed
 *    built-in rows.
 *
 * 3. verify() checks indexes as well as columns, because dbDelta fails silently
 *    on index grammar. See AbstractTableSchema for the grammar rules.
 */
final class Installer {

    public const OPTION_DB_VERSION = 'yayloyalty_db_version';

    /** Mutex transient key. */
    private const LOCK_KEY = 'yayloyalty_upgrading';

    /** Mutex lifetime. Long enough for a slow dbDelta, short enough to self-heal. */
    private const LOCK_TTL = 60;

    /**
     * Registered table schemas.
     *
     * Populated as slices land. Phase 4 adds customers and ledger; Phase 5 adds
     * campaigns.
     *
     * @return AbstractTableSchema[]
     */
    public static function schemas(): array {
        $schemas = [
            new \YayLoyalty\Database\Schema\CustomersTable(),
            new \YayLoyalty\Database\Schema\LedgerTable(),
            new \YayLoyalty\Database\Schema\CampaignsTable(),
            new \YayLoyalty\Database\Schema\RewardsTable(),
        ];

        /**
         * Table schemas to install.
         *
         * @param AbstractTableSchema[] $schemas
         */
        return apply_filters( 'yayloyalty_table_schemas', $schemas );
    }

    /**
     * Run dbDelta for every registered schema, then stamp the version.
     *
     * Idempotent: safe to run repeatedly.
     */
    public static function install(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ( self::schemas() as $schema ) {
            dbDelta( $schema->definition() );
        }

        self::seed_builtins();

        // Runs exactly once, ever — see InitYayLoyaltyCustomer's own docblock
        // for the WooCommerce guard and the idempotency mechanism (an option
        // flag claimed before writing, not stamped after).
        InitYayLoyaltyCustomer::maybe_create();

        // Here rather than only on the activation hook: shop_manager does not
        // exist until WooCommerce has been activated once, and this also runs
        // on every plugin upgrade and for blogs added to a network.
        Capabilities::install();

        self::run_migrations();

        self::verify();

        // Stamped last so a crash mid-install leaves the version behind and the
        // next request retries.
        update_option( self::OPTION_DB_VERSION, YAYLOYALTY_VERSION );
    }

    /**
     * Install or upgrade when the stored version differs from the plugin's.
     *
     * Hooked on plugins_loaded. Cheap on the common path: one option read.
     */
    public static function maybe_upgrade(): void {
        if ( get_option( self::OPTION_DB_VERSION ) === YAYLOYALTY_VERSION ) {
            return;
        }

        if ( ! self::acquire_lock() ) {
            // Another request is upgrading. Return immediately rather than
            // queueing behind a full dbDelta.
            return;
        }

        try {
            // Re-check inside the lock: the holder may have finished between
            // our version read and our lock acquisition.
            if ( get_option( self::OPTION_DB_VERSION ) !== YAYLOYALTY_VERSION ) {
                self::install();
            }
        } finally {
            self::release_lock();
        }
    }

    /**
     * Create every built-in rows.
     *
     * One entry point so install() has a single call for "seed whatever this
     * build ships built-in", rather than a growing list of seed_builtin_*()
     * calls at the call site every time a new definition table gets one.
     * Idempotent by slug, so repeat installs cannot duplicate any of them.
     * Runs inside maybe_upgrade()'s mutex, and each table's unique key on
     * slug is the backstop if two requests somehow seed at once.
     */
    private static function seed_builtins(): void {
        self::seed_builtin_campaigns();
        self::seed_builtin_reward();
    }

    /**
     * Create the built-in campaign rows.
     *
     * Built-ins are addressed by slug rather than id because ids differ between
     * sites — the Free UI and any import/export bind to the slug.
     */
    private static function seed_builtin_campaigns(): void {
        $repository = new \YayLoyalty\Repositories\CampaignRepository();
        $now        = \YayLoyalty\Utils\Dates::now();

        $builtins = [
            'default-purchase' => [
                'name'    => __( 'Points for purchases', 'yayloyalty' ),
                'trigger' => \YayLoyalty\Enums\CampaignTrigger::PURCHASE,
            ],
            'signup'           => [
                'name'    => __( 'Points for signing up', 'yayloyalty' ),
                'trigger' => \YayLoyalty\Enums\CampaignTrigger::SIGNUP,
            ],
            'review'           => [
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
                    // Priority 0: any campaign a merchant creates outranks the
                    // built-in and displaces it.
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

    /**
     * Create the built-in reward row.
     */
    private static function seed_builtin_reward(): void {
        $repository = new \YayLoyalty\Repositories\RewardRepository();
        $now        = \YayLoyalty\Utils\Dates::now();
        $type       = \YayLoyalty\Enums\RewardType::FLEXIBLE_DISCOUNT;

        $repository->seed_builtin(
            new \YayLoyalty\Models\Reward(
                null,
                __( 'Cart discount', 'yayloyalty' ),
                'cart-discount',
                $type,
                \YayLoyalty\Enums\Status::ACTIVE,
                // Sort order 0: the only reward Free offers, first in the list.
                0,
                // cost_points stays null — flexible_discount is the slider type.
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

    /**
     * Data migrations that dbDelta cannot express (backfills, index drops,
     * reshapes).
     *
     * Deliberately an empty seam. A migration base class with zero concrete
     * migrations would be an abstraction designed for a future that has not
     * arrived; introduce it alongside the first real migration, which is the
     * first moment its shape is knowable.
     */
    private static function run_migrations(): void {
        /**
         * Fires after tables are created/updated and before the version stamp.
         */
        do_action( 'yayloyalty_run_migrations' );
    }

    /**
     * Confirm dbDelta actually applied each schema.
     *
     * dbDelta reports success even when it silently skipped an index it could
     * not parse, so this is the only thing standing between a grammar typo and
     * a missing unique key in production.
     *
     * @return array<string, string[]> Problems found, keyed by table name.
     */
    public static function verify(): array {
        global $wpdb;

        $problems = [];

        foreach ( self::schemas() as $schema ) {
            $table  = $schema->table_name();
            $issues = [];

            // get_col() returns the first column of SHOW COLUMNS, which is Field.
            $columns = $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB
            $columns = is_array( $columns ) ? $columns : [];

            foreach ( $schema->expected_columns() as $column ) {
                if ( ! in_array( $column, $columns, true ) ) {
                    $issues[] = 'missing column: ' . $column;
                }
            }

            $index_rows  = $wpdb->get_results( 'SHOW INDEX FROM `' . esc_sql( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB
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
                    // The likely cause is dbDelta silently rejecting the index
                    // grammar, not a genuinely absent column.
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

    /**
     * Acquire the upgrade mutex.
     *
     * add_option() with autoload off is atomic enough for this: it fails when
     * the row already exists. Transients can be served from a non-atomic object
     * cache, so they are not safe as a lock.
     */
    private static function acquire_lock(): bool {
        $acquired = add_option( self::LOCK_KEY, time(), '', 'no' );

        if ( $acquired ) {
            return true;
        }

        // Reclaim an abandoned lock (fatal error mid-upgrade).
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

    /**
     * Install problems are logged at error level, not debug.
     *
     * A table that failed to create is a permanent fault, and a production site
     * has WP_DEBUG off — which is precisely when the record is needed.
     */
    private static function log( string $message ): void {
        Log::error( 'Installer', $message );
    }
}
