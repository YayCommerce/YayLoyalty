<?php
namespace YayLoyalty\Tests\Integration\Controllers;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;

/**
 * The server, not the archive dialog, refuses to archive a built-in; and the
 * campaign resource carries the real, ledger-derived `points_issued` figure
 * the dialog decides from. Rewritten against real CampaignRepository rows —
 * the original version of this test (added alongside the archive/pause UI on
 * `dev/edd`) targeted `CampaignRestController::dummy_fixture()` ids and a
 * `points_issued_lifetime` field; both were removed by "Campaign CRUD goes
 * real" landing first, and the merge that brought this test in left
 * `delete_item()` calling the fixture lookup it also removed. See
 * project-changelog.md's "Campaign archive, pause and bulk actions" entry
 * for that fixture's own original intent.
 */
final class CampaignArchiveBuiltinTest extends IntegrationTestCase {

    protected function setUp(): void {
        parent::setUp();

        $admins = get_users(
            [
                'role'   => 'administrator',
                'number' => 1,
                'fields' => 'ID',
            ]
        );
        $this->assertNotEmpty( $admins, 'The site needs one administrator to act as the caller.' );

        wp_set_current_user( (int) $admins[0] );
        rest_get_server(); // Fires rest_api_init, which is what registers the plugin's routes.
    }

    protected function tearDown(): void {
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    public function test_archiving_a_builtin_is_refused(): void {
        $id = $this->insert_campaign( true );

        $this->assertSame( 403, $this->put_status( $id, 'archived' )->get_status() );
    }

    public function test_pausing_a_builtin_is_allowed(): void {
        $id = $this->insert_campaign( true );

        $this->assertSame( 200, $this->put_status( $id, 'paused' )->get_status() );
    }

    public function test_archiving_a_regular_campaign_is_allowed(): void {
        $id = $this->insert_campaign( false );
        $this->award( $id, 412300 );

        $response = $this->put_status( $id, 'archived' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 412300, $response->get_data()['points_issued'] );
    }

    /**
     * An ended campaign's performance window covers its whole run, so its
     * lifetime `points_issued` and its performance total are one fact —
     * both are unconditional SUMs over the same ledger rows (see
     * CampaignPerformanceRestController's "TWO TIME BASES ON PURPOSE").
     */
    public function test_an_ended_campaigns_points_issued_matches_its_performance_total(): void {
        $id = $this->insert_campaign( false, '2026-06-01 00:00:00', '2026-06-15 00:00:00' );
        $this->award( $id, 15384 );

        $campaign    = rest_do_request( new \WP_REST_Request( 'GET', "/yayloyalty/v1/campaigns/{$id}" ) );
        $performance = rest_do_request( new \WP_REST_Request( 'GET', "/yayloyalty/v1/campaigns/{$id}/performance" ) );

        $this->assertSame( 200, $campaign->get_status() );
        $this->assertSame( 200, $performance->get_status() );
        $this->assertSame( 15384, $campaign->get_data()['points_issued'] );
        $this->assertSame( $performance->get_data()['totals']['points_issued'], $campaign->get_data()['points_issued'] );
    }

    private function put_status( int $id, string $status ): \WP_REST_Response {
        $request = new \WP_REST_Request( 'PUT', sprintf( '/yayloyalty/v1/campaigns/%d', $id ) );
        $request->set_body_params( [ 'status' => $status ] );

        return rest_do_request( $request );
    }

    private function insert_campaign( bool $is_builtin, ?string $starts_at = null, ?string $ends_at = null ): int {
        static $sequence = 0;
        ++$sequence;

        return ( new CampaignRepository() )->insert(
            new Campaign(
                null,
                'Archive test ' . $sequence,
                'archive-test-' . $sequence,
                CampaignTrigger::PURCHASE,
                Status::ACTIVE,
                0,
                [],
                [],
                $starts_at,
                $ends_at,
                $is_builtin
            )
        );
    }

    /** One real EARN row, straight through the repository — same reasoning CampaignPerformanceRepositoryTest has for bypassing LedgerService::earn(). */
    private function award( int $campaign_id, int $points ): void {
        ( new LedgerRepository() )->insert(
            new LedgerEntry(
                null,
                900001,
                $points,
                0,
                LedgerType::EARN,
                LedgerSource::ORDER,
                ReferenceType::ORDER,
                555000 + $campaign_id,
                $campaign_id,
                null,
                555000 + $campaign_id,
                null,
                null,
                Dates::now(),
                null
            )
        );
    }
}
