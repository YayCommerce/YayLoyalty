<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Pro\Storefront\AccountReferrals;

defined( 'ABSPATH' ) || exit;

/** The "Refer a friend" row in "Ways to earn", while the referral program is live, linking a customer to their Referrals tab. */
final class ReferralEarnRow {

    public function register(): void {
        add_filter( 'yayloyalty_launcher_earn_rows', [ $this, 'add_row' ], 10, 3 );
    }

    /**
     * @param array[] $rows      Each row as the yayloyalty_launcher_earn_rows filter documents it (LauncherEarnRules::member_rows()).
     * @param array   $earn      LauncherEarnRules::advertise()'s `earn`.
     * @param int     $viewer_id The viewer the rows are for; 0 for a guest.
     * @return array[]
     */
    public function add_row( array $rows, array $earn, int $viewer_id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the filter passes $earn; this row doesn't read it.
        $settings = ReferralSettings::get();

        if ( ! $settings['enabled'] ) {
            return $rows;
        }

        $rows[] = [
            'trigger'     => CampaignTrigger::REFERRAL,
            'label'       => __( 'Refer a friend', 'yayloyalty' ),
            'value'       => LauncherCopy::points_gain( (int) $settings['advocate_points'] ),
            'description' => __( 'When a friend you referred completes their first order.', 'yayloyalty' ),
            'url'         => AccountReferrals::is_available( $viewer_id ) ? AccountReferrals::url() : '',
        ];

        return $rows;
    }
}
