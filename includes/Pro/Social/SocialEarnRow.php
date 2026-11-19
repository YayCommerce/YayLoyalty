<?php
namespace YayLoyalty\Pro\Social;

use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * One "Ways to earn" row per live social campaign. A customer who hasn't earned it gets the claim link;
 * one who has, and a guest, get a plain link to the network.
 */
final class SocialEarnRow {

    public function register(): void {
        add_filter( 'yayloyalty_launcher_earn_rows', [ $this, 'add_row' ], 10, 3 );
    }

    /**
     * @param array[] $rows      Each row as the yayloyalty_launcher_earn_rows filter documents it (LauncherEarnRules::member_rows()).
     * @param array   $earn      LauncherEarnRules::advertise()'s `earn`.
     * @param int     $viewer_id The viewer the rows are for; 0 for a guest.
     * @return array[]
     */
    public function add_row( array $rows, array $earn, int $viewer_id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the filter passes $earn; these rows don't read it.
        foreach ( SocialClaim::live_campaigns() as $campaign ) {
            $points = $campaign->flat_points();

            if ( $points <= 0 ) {
                continue;
            }

            $config = $campaign->get_config();
            $url    = (string) $config['url'];

            if ( $viewer_id <= 0 ) {
                $description = __( 'Sign in to earn.', 'yayloyalty' );
            } elseif ( self::has_earned( $viewer_id, $campaign ) ) {
                $description = __( 'Earned', 'yayloyalty' );
            } else {
                $description = __( 'Earn once, the first time you click.', 'yayloyalty' );
                $url         = SocialClaim::url( (int) $campaign->get_id() );
            }

            $rows[] = [
                'trigger'     => CampaignTrigger::SOCIAL,
                'label'       => self::label( $config ),
                'value'       => LauncherCopy::points_gain( $points ),
                'description' => $description,
                'url'         => $url,
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $config */
    private static function label( array $config ): string {
        $network = SocialHandler::NETWORKS[ $config['network'] ?? '' ] ?? '';

        /* translators: %s: a social network's name, as "Instagram". */
        return 'share' === ( $config['action'] ?? '' ) ? sprintf( __( 'Share on %s', 'yayloyalty' ), $network ) : sprintf( __( 'Follow us on %s', 'yayloyalty' ), $network );
    }

    private static function has_earned( int $user_id, Campaign $campaign ): bool {
        $key = IdempotencyKey::for_earn( $user_id, ReferenceType::SOCIAL, (int) $campaign->get_id(), CampaignTrigger::SOCIAL );

        return null !== ( new LedgerRepository() )->find_by_idempotency_key( $key );
    }
}
