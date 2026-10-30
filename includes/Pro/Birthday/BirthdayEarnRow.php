<?php
namespace YayLoyalty\Pro\Birthday;

use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\LauncherEarnRules;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The birthday row in "Ways to earn", while a birthday campaign pays everyone. A guest is told to create an
 * account and add a birthday; a customer with no birthday on file gets the label as a link to Account details,
 * where they can add it.
 */
final class BirthdayEarnRow {

    /** @var int|null|false The advertised points, once per request since several placements can render on a page; false until worked out. */
    private static $points = false;

    /** @var array<int, bool> User id => whether they have no birthday on file, once per request. */
    private static $missing_birthday = [];

    public function register(): void {
        add_filter( 'yayloyalty_launcher_earn_rows', [ $this, 'add_row' ], 10, 3 );
    }

    /**
     * @param array[] $rows      Each row as the yayloyalty_launcher_earn_rows filter documents it (LauncherEarnRules::member_rows()).
     * @param array   $earn      LauncherEarnRules::advertise()'s `earn`.
     * @param int     $viewer_id The viewer the rows are for; 0 for a guest.
     * @return array[]
     */
    public function add_row( array $rows, array $earn, int $viewer_id ): array {
        $points = self::advertised_points();

        if ( null === $points ) {
            return $rows;
        }

        $url = '';

        if ( $viewer_id <= 0 ) {
            $description = __( 'Create an account and add your birthday to get a birthday bonus.', 'yayloyalty' );
        } elseif ( self::has_no_birthday( $viewer_id ) ) {
            $description = __( 'Tell us your birthday to get a birthday bonus.', 'yayloyalty' );
            $url         = wc_customer_edit_account_url();
        } else {
            /* translators: %s: points with the store's label, as "100 Points". */
            $description = sprintf( __( 'You\'ll receive %s on your next birthday.', 'yayloyalty' ), SettingsHelper::points_text( $points ) );
        }

        $rows[] = [
            'trigger'     => CampaignTrigger::BIRTHDAY,
            'label'       => __( 'Celebrate your birthday', 'yayloyalty' ),
            'value'       => LauncherCopy::points_gain( $points ),
            'description' => $description,
            'url'         => $url,
        ];

        return $rows;
    }

    /** The points a birthday campaign pays every customer; null when none does. */
    private static function advertised_points(): ?int {
        if ( false === self::$points ) {
            $advert       = LauncherEarnRules::advertised( PointsEngine::build( new CampaignRepository() ), CampaignTrigger::BIRTHDAY, ContextFactory::for_advert( Dates::now() ) );
            self::$points = null !== $advert ? (int) $advert['value'] : null;
        }

        return self::$points;
    }

    private static function has_no_birthday( int $user_id ): bool {
        if ( ! isset( self::$missing_birthday[ $user_id ] ) ) {
            $customer = ( new CustomerRepository() )->find_by_user_id( $user_id );

            self::$missing_birthday[ $user_id ] = null === $customer || null === $customer->get_birthday_month();
        }

        return self::$missing_birthday[ $user_id ];
    }
}
