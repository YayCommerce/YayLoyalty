<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Points\Breakdown;
use YayLoyalty\Engine\Points\CapPolicy;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Points\Triggers\Registry;
use YayLoyalty\Engine\Points\Triggers\TriggerHandler;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;
use YayLoyalty\Utils\Dates;

/** EarningService::award() against real orders: re-evaluation, idempotency, pending, and the after-commit hook. */
final class EarningServiceAwardTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    /** A campaign that ended between purchase and award still pays: both moments score at the order's creation date. */
    public function test_award_reevaluates_at_the_same_order_creation_date_as_the_preview(): void {
        $order = $this->create_order( $this->create_customer(), [ 100 ] );
        $order->set_date_created( Dates::to_timestamp( Dates::offset( -2 * DAY_IN_SECONDS ) ) );
        $order->save();
        $this->create_campaign( CampaignTrigger::PURCHASE, [], Dates::offset( -DAY_IN_SECONDS ) );

        $ctx     = ContextFactory::from_order( $order );
        $service = EarningService::build();
        $outcome = $service->award( CampaignTrigger::PURCHASE, $ctx );

        $this->assertSame( Outcome::AWARDED, $outcome->get_status() );
        $this->assertEquals( $service->preview( CampaignTrigger::PURCHASE, $ctx ), $outcome->get_evaluation() );

        $placed_today = ContextFactory::from_order( $this->create_order( $this->create_customer(), [ 100 ] ) );
        $this->assertFalse( $service->preview( CampaignTrigger::PURCHASE, $placed_today )->is_awarded(), 'The campaign has ended for an order placed now.' );
    }

    public function test_award_returns_duplicate_as_a_normal_outcome_not_an_exception(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();
        $ctx     = ContextFactory::from_order( $this->create_order( $user_id, [ 100 ] ) );
        $service = EarningService::build();

        $first  = $service->award( CampaignTrigger::PURCHASE, $ctx );
        $before = $this->customer_row( $user_id );
        $second = $service->award( CampaignTrigger::PURCHASE, $ctx );

        $this->assertSame( Outcome::DUPLICATE, $second->get_status() );
        $this->assertSame( $first->get_entry()->get_id(), $second->get_entry()->get_id() );
        $this->assertSame( $before, $this->customer_row( $user_id ) );
    }

    /** No campaign existed at checkout, so nothing was staged; the award must not care. */
    public function test_award_does_not_require_staging_to_have_run(): void {
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 100 ] );
        $this->create_campaign();

        $outcome = EarningService::build()->award( CampaignTrigger::PURCHASE, ContextFactory::from_order( $order ) );

        $this->assertSame( Outcome::AWARDED, $outcome->get_status() );
        $this->assertSame( 0, (int) $this->customer_row( $user_id )['pending_points'] );
        $this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( PendingPoints::META_RELEASED ) );
    }

    public function test_awarded_hook_fires_after_commit_and_only_when_awarded(): void {
        $this->create_campaign();
        $ctx     = ContextFactory::from_order( $this->create_order( $this->create_customer(), [ 100 ] ) );
        $service = EarningService::build();
        $fired   = 0;

        $listener = static function () use ( &$fired ): void {
            ++$fired;
        };
        add_action( 'yayloyalty_points_awarded', $listener );

        try {
            Transaction::run(
                function () use ( $service, $ctx, &$fired ): void {
                    $service->award( CampaignTrigger::PURCHASE, $ctx );
                    $this->assertSame( 0, $fired, 'Fired inside the transaction.' );
                }
            );
            $this->assertSame( 1, $fired );

            $service->award( CampaignTrigger::PURCHASE, $ctx );
            $service->award( CampaignTrigger::PURCHASE, ContextFactory::from_order( $this->create_order( null, [ 100 ] ) ) );
        } finally {
            remove_action( 'yayloyalty_points_awarded', $listener );
        }

        $this->assertSame( 1, $fired, 'Fired for a duplicate or a skip.' );
    }

    public function test_one_order_can_award_once_per_trigger(): void {
        $this->create_campaign();
        $this->create_campaign( CampaignTrigger::FIRST_ORDER, [ 'points' => 25 ] );
        $user_id = $this->create_customer();
        $ctx     = ContextFactory::from_order( $this->create_order( $user_id, [ 100 ] ) );
        $service = $this->service_with_first_order();

        $service->award( CampaignTrigger::PURCHASE, $ctx );
        $service->award( CampaignTrigger::FIRST_ORDER, $ctx );

        $rows = $this->ledger_rows( $user_id );

        $this->assertCount( 2, $rows );
        $this->assertNotSame( $rows[0]['idempotency_key'], $rows[1]['idempotency_key'] );
        $this->assertSame( 125, (int) $this->customer_row( $user_id )['balance'] );
    }

    /** A duplicate means another request already awarded and released; this one must not release again. */
    public function test_duplicate_leaves_pending_untouched(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();
        $order   = $this->create_order( $user_id, [ 100 ] );

        $this->ledger_service()->earn( $this->earn_draft( $user_id, (int) $order->get_id(), 100 ) );
        $outcome = EarningService::build()->award( CampaignTrigger::PURCHASE, ContextFactory::from_order( $order ) );

        $this->assertSame( Outcome::DUPLICATE, $outcome->get_status() );
        $this->assertSame( 100, (int) $this->customer_row( $user_id )['pending_points'] );
    }

    /** The Lite engine plus a flat first_order handler, standing in for Pro's. */
    private function service_with_first_order(): EarningService {
        $campaigns = new CampaignRepository();
        $registry  = Registry::with_lite_handlers( $campaigns );

        $registry->register(
            new class() implements TriggerHandler {
                public function trigger(): string {
                    return CampaignTrigger::FIRST_ORDER;
                }

                public function is_eligible( Campaign $campaign, Context $ctx ): bool {
                    return true;
                }

                public function compute_points( Campaign $campaign, Context $ctx ): Breakdown {
                    return Breakdown::flat( (int) $campaign->get_config()['points'] );
                }

                public function reference_type(): string {
                    return ReferenceType::ORDER;
                }

                public function reference_id( Context $ctx ): ?int {
                    return $ctx->get_order_id();
                }

                public function source(): string {
                    return LedgerSource::ORDER;
                }
            }
        );

        return new EarningService(
            new PointsEngine( $registry, $campaigns, new CapPolicy() ),
            $this->ledger_service(),
            new PendingPoints( new CustomerRepository() ),
            [ new ProgramGate() ]
        );
    }
}
