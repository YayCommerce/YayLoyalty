<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Helpers\DesignHelper;

defined( 'ABSPATH' ) || exit;

/**
 * What a merchant places on a page: the points card, "Ways to earn" and "Ways to redeem", as shortcodes and blocks.
 * Both print the Rewards tab's own partials, so the theme styles them once.
 */
final class Placements {

    public const BALANCE_SHORTCODE = 'yayloyalty_balance';

    public const WAYS_TO_EARN_SHORTCODE = 'yayloyalty_ways_to_earn';

    public const WAYS_TO_REDEEM_SHORTCODE = 'yayloyalty_ways_to_redeem';

    public const BLOCK_CATEGORY = 'yayloyalty';

    private const EDITOR_SCRIPT_PATH = 'assets/dist/blocks/editor.js';

    /** Hooked on every request: the block editor previews through REST, which is neither admin nor front end. */
    public static function register(): void {
        add_shortcode( self::BALANCE_SHORTCODE, [ self::class, 'balance' ] );
        add_shortcode( self::WAYS_TO_EARN_SHORTCODE, [ self::class, 'ways_to_earn' ] );
        add_shortcode( self::WAYS_TO_REDEEM_SHORTCODE, [ self::class, 'ways_to_redeem' ] );
        add_action( 'init', [ self::class, 'register_blocks' ] );
        add_filter( 'block_categories_all', [ self::class, 'add_block_category' ] );
    }

    public static function register_blocks(): void {
        wp_register_script(
            ScriptName::BLOCKS_EDITOR_SCRIPT,
            YAYLOYALTY_PLUGIN_URL . self::EDITOR_SCRIPT_PATH,
            [ 'react', 'wp-blocks', 'wp-block-editor', 'wp-server-side-render' ],
            Launcher::asset_version( self::EDITOR_SCRIPT_PATH ),
            true
        );

        register_block_type( __DIR__ . '/blocks/points-balance', [ 'render_callback' => [ self::class, 'balance_block' ] ] );
        register_block_type( __DIR__ . '/blocks/ways-to-earn', [ 'render_callback' => [ self::class, 'ways_to_earn_block' ] ] );
        register_block_type( __DIR__ . '/blocks/ways-to-redeem', [ 'render_callback' => [ self::class, 'ways_to_redeem_block' ] ] );
    }

    /**
     * @param array<int, array{slug: string, title: string}> $categories
     * @return array<int, array{slug: string, title: string}>
     */
    public static function add_block_category( array $categories ): array {
        if ( in_array( self::BLOCK_CATEGORY, array_column( $categories, 'slug' ), true ) ) {
            return $categories;
        }

        return array_merge(
            [
                [
                    'slug'  => self::BLOCK_CATEGORY,
                    'title' => 'YayLoyalty',
                ],
            ],
            $categories
        );
    }

    /** [yayloyalty_balance]: the points card, or a sign-in link for a guest. */
    public static function balance(): string {
        return self::render(
            static function (): void {
                $yayloyalty_card = AccountRewards::card_view( get_current_user_id() );

                require __DIR__ . '/templates/points-card.php';
            }
        );
    }

    /** [yayloyalty_ways_to_earn]: the earning rules this viewer can use. */
    public static function ways_to_earn(): string {
        return self::render(
            static function (): void {
                $user_id         = get_current_user_id();
                $yayloyalty_earn = LauncherEarnRules::for_viewer( LauncherPayload::for_user( DesignHelper::get_design(), $user_id ), $user_id );

                require __DIR__ . '/templates/ways-to-earn.php';
            }
        );
    }

    /**
     * [yayloyalty_ways_to_redeem]: the points discount and the offers, as My Account lists them (the Design tab's
     * reward-catalog switch hides offers in the launcher only). Nothing for a shopper checkout would refuse.
     */
    public static function ways_to_redeem(): string {
        return self::render(
            static function (): void {
                $yayloyalty_payload = LauncherPayload::for_user( DesignHelper::get_design(), get_current_user_id() );

                require __DIR__ . '/templates/ways-to-redeem.php';
            }
        );
    }

    /** The "Points balance" block: [yayloyalty_balance] in the block's wrapper. */
    public static function balance_block(): string {
        return self::in_block_wrapper( self::balance() );
    }

    /** The "Ways to earn" block: [yayloyalty_ways_to_earn] in the block's wrapper. */
    public static function ways_to_earn_block(): string {
        return self::in_block_wrapper( self::ways_to_earn() );
    }

    /** The "Ways to redeem" block: [yayloyalty_ways_to_redeem] in the block's wrapper. */
    public static function ways_to_redeem_block(): string {
        return self::in_block_wrapper( self::ways_to_redeem() );
    }

    private static function in_block_wrapper( string $html ): string {
        return '' === $html ? '' : sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $html );
    }

    /**
     * A placement's HTML in the tab's wrapper; '' while the program is off, when there is nothing to show,
     * or when it fails, which costs only this placement.
     *
     * @param callable $output Prints the partial.
     */
    private static function render( callable $output ): string {
        if ( ! ProgramGate::program_enabled() ) {
            return '';
        }

        $html = trim( (string) OutputCapture::capture( $output, 'Placements' ) );

        if ( '' === $html ) {
            return '';
        }

        wp_enqueue_style( ScriptName::ACCOUNT_STYLE );

        return '<div class="yayloyalty-account yayloyalty-placement">' . $html . '</div>';
    }
}
