<?php
namespace YayLoyalty\Pro\Birthday;

use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The birthday row in "Ways to earn", while a birthday campaign pays everyone. A signed-in customer with no
 * birthday on file gets the label as a link to Account details, where they can add it.
 */
final class BirthdayEarnRow {

    /** @var PointsEngine|null Built once per request: several placements can render on one page. */
    private static $engine = null;

    public function register(): void {
        add_filter( 'yayloyalty_launcher_earn_rows', [ $this, 'add_row' ] );
    }

    /**
     * @param array<int, array{trigger: string, label: string, value: string, url?: string}> $rows
     * @return array<int, array{trigger: string, label: string, value: string, url?: string}>
     */
    public function add_row( array $rows ): array {
        $points = self::advertised_points();

        if ( null === $points ) {
            return $rows;
        }

        $rows[] = [
            'trigger' => CampaignTrigger::BIRTHDAY,
            'label'   => __( 'Celebrate your birthday', 'yayloyalty' ),
            'value'   => SettingsHelper::points_text( $points ),
            'url'     => self::has_no_birthday( get_current_user_id() ) ? wc_customer_edit_account_url() : '',
        ];

        return $rows;
    }

    /** The points a birthday campaign pays every customer; null when none does. A failing engine costs only this row. */
    private static function advertised_points(): ?int {
        try {
            self::$engine = self::$engine ?? PointsEngine::build( new CampaignRepository() );
            $advert       = self::$engine->advertised( CampaignTrigger::BIRTHDAY, ContextFactory::for_advert( Dates::now() ) );
        } catch ( \Throwable $e ) {
            Log::warning( 'BirthdayEarnRow', 'birthday earn row failed: ' . $e->getMessage() );

            return null;
        }

        return null !== $advert ? (int) $advert['value'] : null;
    }

    private static function has_no_birthday( int $user_id ): bool {
        if ( $user_id <= 0 ) {
            return false;
        }

        $customer = ( new CustomerRepository() )->find_by_user_id( $user_id );

        return null === $customer || null === $customer->get_birthday_month();
    }
}
