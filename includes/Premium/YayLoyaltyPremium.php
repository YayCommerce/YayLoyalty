<?php
namespace YayLoyalty\Premium;

defined( 'ABSPATH' ) || exit;

/**
 * Premium entry point.
 *
 * THIS ENTIRE DIRECTORY IS DELETED FOR THE LITE BUILD. Nothing outside
 * includes/Premium/ may reference a class inside it, with exactly one
 * exception: the guarded class_exists probe in YayLoyalty::initialize().
 *
 * That single guarded reference is what makes the split a directory deletion
 * rather than surgery on shared files — no @premium-start markers anywhere.
 *
 * WIRING ONLY. boot() attaches hooks and constructs collaborators; it does not
 * implement behaviour. Every feature lives in its own layer directory
 * (Models/, Repositories/, Services/, Controllers/, Database/Schema/) mirroring
 * the structure of the Lite tree, so a reader always knows where to look.
 *
 * Premium reaches Lite only through the seams documented in
 * docs/code-standards.md section 13:
 *
 *   yayloyalty_table_schemas
 *   yayloyalty_ledger_recorded
 *   yayloyalty_rest_controllers
 *   yayloyalty_campaign_triggers
 *   yayloyalty_campaign_config_whitelist
 *   yayloyalty_rest_item_schema
 *   yayloyalty_campaign_conditions_evaluator
 *
 * If a feature needs something no seam provides, add a seam to Lite. Reaching
 * for a marker instead is the thing this design exists to avoid.
 */
final class YayLoyaltyPremium {

    /** Guards against double-booting if initialize() ever runs twice. */
    private static $booted = false;

    /**
     * Attach Premium to Lite's extension seams.
     *
     * Called from YayLoyalty::initialize() behind a class_exists check, so in
     * the Lite build this method simply does not exist and is never reached.
     */
    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;

        /**
         * Fires once Premium has attached its hooks.
         *
         * The extension point for Premium-internal add-ons; not part of the
         * Lite build's surface.
         */
        do_action( 'yayloyalty_premium_booted' );
    }

    /**
     * Has Premium booted in this request?
     *
     * Distinct from YayLoyalty::is_premium(), which only asks whether the code
     * is present. Useful when ordering matters.
     */
    public static function is_booted(): bool {
        return self::$booted;
    }
}
