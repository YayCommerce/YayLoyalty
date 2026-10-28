<?php
namespace YayLoyalty\Integrations\YayPricing;

use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * A customer's points earned and spent, lifetime and this site-calendar month, net of refunds as
 * the customer row counts them. Memoised per instance: product pricing rules ask once per product,
 * so one request reads each figure once. Names no YayPricing class, so it loads without YayPricing.
 */
final class CustomerPointsTotals {

    public const EARNED       = 'earned';
    public const SPENT        = 'spent';
    public const EARNED_MONTH = 'earned_month';
    public const SPENT_MONTH  = 'spent_month';

    /** @var LedgerRepository */
    private $ledger;

    /** @var CustomerRepository */
    private $customers;

    /** @var array<int, array<string, int>> */
    private $memo = [];

    public function __construct( ?LedgerRepository $ledger = null, ?CustomerRepository $customers = null ) {
        $this->ledger    = $ledger ?? new LedgerRepository();
        $this->customers = $customers ?? new CustomerRepository();
    }

    /** The metric's points for the user; 0 for an unknown metric or a user with no customer row. */
    public function get( int $user_id, string $metric ): int {
        if ( ! isset( $this->memo[ $user_id ][ $metric ] ) ) {
            $this->memo[ $user_id ][ $metric ] = $this->read( $user_id, $metric );
        }

        return $this->memo[ $user_id ][ $metric ];
    }

    private function read( int $user_id, string $metric ): int {
        switch ( $metric ) {
            case self::EARNED:
            case self::SPENT:
                $customer = $this->customers->find_by_user_id( $user_id );

                if ( null === $customer ) {
                    return 0;
                }

                return self::EARNED === $metric ? $customer->get_lifetime_earned() : $customer->get_lifetime_redeemed();
            case self::EARNED_MONTH:
                return $this->ledger->earned_since( $user_id, self::month_start() );
            case self::SPENT_MONTH:
                return $this->ledger->redeemed_since( $user_id, self::month_start() );
            default:
                return 0;
        }
    }

    /** UTC bound of 00:00 on day 1 of the current site-calendar month. */
    private static function month_start(): string {
        return Dates::to_utc( Dates::site_month_start( Dates::now() ) );
    }
}
