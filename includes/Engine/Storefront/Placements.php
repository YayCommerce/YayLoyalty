<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * What a merchant places on a page: the points card and "Ways to earn", as shortcodes.
 * Both print the Rewards tab's own partials, so the theme styles them once.
 */
final class Placements {

    public const BALANCE_SHORTCODE = 'yayloyalty_balance';

    public const WAYS_TO_EARN_SHORTCODE = 'yayloyalty_ways_to_earn';

    /** Hooked on every request: the block editor previews through REST, which is neither admin nor front end. */
    public static function register(): void {
        add_shortcode( self::BALANCE_SHORTCODE, [ self::class, 'balance' ] );
        add_shortcode( self::WAYS_TO_EARN_SHORTCODE, [ self::class, 'ways_to_earn' ] );
    }

    /** [yayloyalty_balance]: the points card, or a sign-in link for a guest. */
    public static function balance(): string {
        return self::render(
            'balance',
            static function (): void {
                $yayloyalty_card = AccountRewards::card_view( get_current_user_id() );

                require __DIR__ . '/templates/points-card.php';
            }
        );
    }

    /** [yayloyalty_ways_to_earn]: the earning rules this viewer can use. */
    public static function ways_to_earn(): string {
        return self::render(
            'ways_to_earn',
            static function (): void {
                $user_id         = get_current_user_id();
                $yayloyalty_earn = LauncherEarnRules::for_viewer( AccountRewards::card_view( $user_id )['payload'], $user_id );

                require __DIR__ . '/templates/ways-to-earn.php';
            }
        );
    }

    /**
     * A placement's HTML in the tab's wrapper; '' while the program is off, when there is nothing to show,
     * or when it fails, which costs only this placement.
     *
     * @param string   $name   For the log.
     * @param callable $output Prints the partial.
     */
    private static function render( string $name, callable $output ): string {
        if ( ! ProgramGate::program_enabled() ) {
            return '';
        }

        $buffer_level = ob_get_level();

        try {
            ob_start();
            $output();
            $html = trim( (string) ob_get_clean() );
        } catch ( \Throwable $e ) {
            // Drop the half-printed placement, never the theme's own buffers.
            while ( ob_get_level() > $buffer_level ) {
                ob_end_clean();
            }

            Log::warning( 'Placements', $name . ' not rendered: ' . $e->getMessage() );

            return '';
        }

        if ( '' === $html ) {
            return '';
        }

        AccountRewards::enqueue_style();

        return '<div class="yayloyalty-account yayloyalty-placement">' . $html . '</div>';
    }
}
