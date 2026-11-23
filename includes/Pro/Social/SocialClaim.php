<?php
namespace YayLoyalty\Pro\Social;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Listeners\PreviewLog;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The claim link of a social row in "Ways to earn": pays the campaign's points once, then sends the
 * customer on to the campaign's stored URL. Never redirects to a URL taken from the request.
 */
final class SocialClaim {

    public const QUERY_ARG = 'yl_social';

    private const NONCE_PREFIX = 'yayloyalty_social_';

    public static function url( int $campaign_id ): string {
        return wp_nonce_url( add_query_arg( self::QUERY_ARG, $campaign_id, home_url( '/' ) ), self::NONCE_PREFIX . $campaign_id );
    }

    /** On `template_redirect`: claims and redirects when the request is a claim link. */
    public static function maybe_claim(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- claim() verifies the nonce before it pays.
        if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- claim() verifies the nonce before it pays.
        $campaign_id = absint( wp_unslash( $_GET[ self::QUERY_ARG ] ) );
        $nonce       = sanitize_key( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        nocache_headers();
        wp_redirect( self::claim( $campaign_id, $nonce, get_current_user_id() ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the network's URL, read from a live social campaign and stored as http(s) only.
        exit;
    }

    /**
     * Pays $campaign_id's points to $user_id when the claim is valid, and returns where to send them:
     * the campaign's URL, or the home page for a campaign that isn't a live social one.
     */
    public static function claim( int $campaign_id, string $nonce, int $user_id ): string {
        $campaign = self::live_campaign( $campaign_id );

        if ( null === $campaign ) {
            return home_url( '/' );
        }

        if ( $user_id > 0 && wp_verify_nonce( $nonce, self::NONCE_PREFIX . $campaign_id ) && ProgramGate::storefront_enabled() ) {
            $outcome = EarningService::build()->award(
                CampaignTrigger::SOCIAL,
                ContextFactory::from_user( $user_id, Dates::now(), [ SocialHandler::EXTRA_CAMPAIGN_ID => $campaign_id ] )
            );

            PreviewLog::write_outcome( 'SocialClaim', 'user #' . $user_id . ' campaign #' . $campaign_id, $outcome );
        }

        return (string) $campaign->get_config()['url'];
    }

    /** @return Campaign[] The social campaigns live now that have a URL to send to, in selection order. */
    public static function live_campaigns(): array {
        return array_values(
            array_filter(
                ( new CampaignRepository() )->live_for_trigger( CampaignTrigger::SOCIAL, Dates::now() ),
                static fn( Campaign $campaign ): bool => '' !== (string) ( $campaign->get_config()['url'] ?? '' )
            )
        );
    }

    private static function live_campaign( int $campaign_id ): ?Campaign {
        foreach ( self::live_campaigns() as $campaign ) {
            if ( $campaign->get_id() === $campaign_id ) {
                return $campaign;
            }
        }

        return null;
    }
}
