<?php
namespace YayLoyalty\Tests\Integration\Controllers;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/**
 * The campaigns route refuses a purchase rate that could never earn and a
 * default purchase campaign that would multiply its own rate.
 */
final class CampaignRateGuardTest extends IntegrationTestCase {

    protected function setUp(): void {
        parent::setUp();

        $this->act_as_admin();
    }

    protected function tearDown(): void {
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    public function test_the_default_purchase_campaign_cannot_switch_to_multiplier_mode(): void {
        $id = $this->insert_purchase( CampaignRepository::DEFAULT_PURCHASE_SLUG, true );

        $response = $this->put_config(
            $id,
            [
                'mode'       => 'multiplier',
                'multiplier' => 2,
            ]
        );

        $this->assertSame( 400, $response->get_status() );
        $this->assertArrayNotHasKey( 'mode', ( new CampaignRepository() )->find_campaign( $id )->get_config() );
    }

    public function test_the_default_purchase_campaign_can_change_its_own_rate(): void {
        $id = $this->insert_purchase( CampaignRepository::DEFAULT_PURCHASE_SLUG, true );

        $this->assertSame( 200, $this->put_config( $id, [ 'points_per_unit' => 2 ] )->get_status() );
    }

    public function test_another_purchase_campaign_can_multiply_the_default(): void {
        $id = $this->insert_purchase( 'rate-guard-boost', false );

        $response = $this->put_config(
            $id,
            [
                'mode'       => 'multiplier',
                'multiplier' => 2,
            ]
        );

        $this->assertSame( 200, $response->get_status() );
    }

    public function test_a_zero_multiplier_or_rate_is_refused(): void {
        $id = $this->insert_purchase( 'rate-guard-zero', false );

        $response = $this->put_config(
            $id,
            [
                'mode'       => 'multiplier',
                'multiplier' => 0,
            ]
        );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 400, $this->put_config( $id, [ 'points_per_unit' => 0 ] )->get_status() );
    }

    public function test_a_rate_mode_purchase_campaign_needs_a_rate(): void {
        $id = $this->insert_purchase( 'rate-guard-missing', false );

        $this->assertSame( 400, $this->put_config( $id, [ 'mode' => 'rate' ] )->get_status() );

        $request = new \WP_REST_Request( 'POST', '/yayloyalty/v1/campaigns' );
        $request->set_body_params(
            [
                'name'    => 'Rate guard no rate',
                'trigger' => CampaignTrigger::PURCHASE,
                'config'  => [ 'rounding' => 'down' ],
            ]
        );

        $this->assertSame( 400, rest_do_request( $request )->get_status() );
    }

    public function test_a_multiplier_needs_no_explicit_factor(): void {
        $id = $this->insert_purchase( 'rate-guard-implicit', false );

        $this->assertSame( 200, $this->put_config( $id, [ 'mode' => 'multiplier' ] )->get_status() );
    }

    /** @param array<string, mixed> $config */
    private function put_config( int $id, array $config ): \WP_REST_Response {
        $request = new \WP_REST_Request( 'PUT', sprintf( '/yayloyalty/v1/campaigns/%d', $id ) );
        $request->set_body_params( [ 'config' => $config ] );

        return rest_do_request( $request );
    }

    private function insert_purchase( string $slug, bool $is_builtin ): int {
        return ( new CampaignRepository() )->insert(
            new Campaign(
                null,
                'Rate guard ' . $slug,
                $slug,
                CampaignTrigger::PURCHASE,
                Status::ACTIVE,
                0,
                [ 'points_per_unit' => 1 ],
                [],
                null,
                null,
                $is_builtin
            )
        );
    }
}
