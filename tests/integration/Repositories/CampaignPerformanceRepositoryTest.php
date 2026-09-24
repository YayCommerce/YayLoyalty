<?php
namespace YayLoyalty\Tests\Integration\Repositories;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Pro\Repositories\CampaignPerformanceRepository;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;

/**
 * CampaignPerformanceRepository against a real ledger — the aggregates
 * CampaignPerformanceFixture's removal left with no other proof. Rows are
 * inserted straight through LedgerRepository, not LedgerService::earn(),
 * since these fixtures need a real campaign_id and (for one case) a
 * negative REFUND_EARN row that earn() refuses to write (EARN rows only) —
 * same reasoning LedgerGuardTest's own "not a duplicate" test has for
 * bypassing the service.
 */
final class CampaignPerformanceRepositoryTest extends IntegrationTestCase {

    private CampaignPerformanceRepository $repository;

    /** A real WP user, so paginate_entries() has a display name to join; totals() has no such dependency. */
    private int $real_user_id;

    protected function setUp(): void {
        parent::setUp();

        $this->repository = new CampaignPerformanceRepository();

        $admins = get_users(
            [
                'number' => 1,
                'fields' => 'ID',
            ]
        );
        $this->assertNotEmpty( $admins, 'The site needs at least one WP user for the entries join.' );
        $this->real_user_id = (int) $admins[0];
    }

    public function test_totals_is_all_zero_for_a_campaign_with_no_rows(): void {
        $campaign = $this->insert_campaign( CampaignTrigger::PURCHASE );

        $this->assertSame(
            [
                'points_issued'    => 0,
                'entries_total'    => 0,
                'orders_affected'  => 0,
                'customers_earned' => 0,
            ],
            $this->repository->totals( $campaign )
        );
    }

    /**
     * Same user, two orders: orders_affected counts DISTINCT order_id (2),
     * customers_earned counts DISTINCT user_id (1) — the two counts must not
     * collapse into each other. A second campaign's row and a negative
     * REFUND_EARN row are both planted to prove they never leak in.
     */
    public function test_totals_scopes_by_campaign_and_counts_positive_earn_rows_only(): void {
        $campaign_a = $this->insert_campaign( CampaignTrigger::PURCHASE );
        $campaign_b = $this->insert_campaign( CampaignTrigger::PURCHASE );

        $this->insert_row(
            $campaign_a,
            [
                'user_id'  => 100,
                'points'   => 100,
                'order_id' => 1001,
            ]
        );
        $this->insert_row(
            $campaign_a,
            [
                'user_id'  => 100,
                'points'   => 50,
                'order_id' => 1002,
            ]
        );
        $this->insert_row(
            $campaign_a,
            [
                'user_id'  => 100,
                'points'   => -20,
                'order_id' => 1001,
                'type'     => LedgerType::REFUND_EARN,
            ]
        );
        $this->insert_row(
            $campaign_b,
            [
                'user_id'  => 200,
                'points'   => 999,
                'order_id' => 2001,
            ]
        );

        $this->assertSame(
            [
                'points_issued'    => 150,
                'entries_total'    => 2,
                'orders_affected'  => 2,
                'customers_earned' => 1,
            ],
            $this->repository->totals( $campaign_a )
        );
    }

    public function test_paginate_entries_scopes_orders_and_pages(): void {
        $campaign = $this->insert_campaign( CampaignTrigger::REVIEW );
        $other    = $this->insert_campaign( CampaignTrigger::REVIEW );

        $this->insert_row(
            $other,
            [
                'user_id'        => $this->real_user_id,
                'points'         => 999,
                'reference_type' => ReferenceType::COMMENT,
                'reference_id'   => 1,
                'order_id'       => null,
            ]
        );

        $oldest = Dates::offset( -7200 );
        $middle = Dates::offset( -3600 );
        $newest = Dates::now();

        $this->insert_row(
            $campaign,
            [
                'user_id'        => $this->real_user_id,
                'points'         => 10,
                'reference_type' => ReferenceType::COMMENT,
                'reference_id'   => 501,
                'order_id'       => null,
                'created_at'     => $oldest,
            ]
        );
        $this->insert_row(
            $campaign,
            [
                'user_id'        => $this->real_user_id,
                'points'         => 20,
                'reference_type' => ReferenceType::COMMENT,
                'reference_id'   => 502,
                'order_id'       => null,
                'created_at'     => $middle,
            ]
        );
        $this->insert_row(
            $campaign,
            [
                'user_id'        => $this->real_user_id,
                'points'         => 30,
                'reference_type' => ReferenceType::COMMENT,
                'reference_id'   => 503,
                'order_id'       => null,
                'created_at'     => $newest,
            ]
        );

        $page1 = $this->repository->paginate_entries( $campaign, 1, 2, '' );

        $this->assertSame( 3, $page1['total'] );
        $this->assertCount( 2, $page1['items'] );
        // Newest first: the two most recent rows (503 then 502), never the other campaign's row.
        $this->assertSame( 503, $page1['items'][0]['reference']['id'] );
        $this->assertSame( 502, $page1['items'][1]['reference']['id'] );
        $this->assertSame( get_userdata( $this->real_user_id )->display_name, $page1['items'][0]['customer_name'] );

        $page2 = $this->repository->paginate_entries( $campaign, 2, 2, '' );

        $this->assertCount( 1, $page2['items'] );
        $this->assertSame( 501, $page2['items'][0]['reference']['id'] );
    }

    public function test_paginate_entries_search_matches_name_or_reference_and_guards_a_bare_hash(): void {
        $campaign = $this->insert_campaign( CampaignTrigger::REVIEW );
        $name     = get_userdata( $this->real_user_id )->display_name;

        $this->insert_row(
            $campaign,
            [
                'user_id'        => $this->real_user_id,
                'points'         => 10,
                'reference_type' => ReferenceType::COMMENT,
                'reference_id'   => 4477,
                'order_id'       => null,
            ]
        );

        $this->assertSame( 1, $this->repository->paginate_entries( $campaign, 1, 10, mb_substr( $name, 0, 3 ) )['total'] );
        $this->assertSame( 1, $this->repository->paginate_entries( $campaign, 1, 10, '477' )['total'] );
        $this->assertSame( 0, $this->repository->paginate_entries( $campaign, 1, 10, 'no-such-match-xyz' )['total'] );
        // '#' alone ltrims to '' — must match nothing, not every row (see the repository's own guard comment).
        $this->assertSame( 0, $this->repository->paginate_entries( $campaign, 1, 10, '#' )['total'] );
        $this->assertSame( 1, $this->repository->paginate_entries( $campaign, 1, 10, '#4477' )['total'] );
    }

    public function test_paginate_entries_shapes_each_reference_type(): void {
        $campaign = $this->insert_campaign( CampaignTrigger::PURCHASE );

        $this->insert_row(
            $campaign,
            [
                'user_id'        => $this->real_user_id,
                'points'         => 10,
                'reference_type' => ReferenceType::ORDER,
                'reference_id'   => 999999999,
                'order_id'       => 999999999,
            ]
        );
        $this->insert_row(
            $campaign,
            [
                'user_id'        => $this->real_user_id,
                'points'         => 20,
                'reference_type' => ReferenceType::COMMENT,
                'reference_id'   => 555,
                'order_id'       => null,
            ]
        );
        $this->insert_row(
            $campaign,
            [
                'user_id'        => $this->real_user_id,
                'points'         => 30,
                'reference_type' => ReferenceType::USER,
                'reference_id'   => $this->real_user_id,
                'order_id'       => null,
            ]
        );

        $items     = $this->repository->paginate_entries( $campaign, 1, 10, '' )['items'];
        $by_points = [];
        foreach ( $items as $item ) {
            $by_points[ $item['points'] ] = $item;
        }

        // Order reference: shaped, but the order itself does not exist — subtotal is null, not a fatal WC lookup.
        $this->assertSame(
            [
                'id'    => 999999999,
                'label' => '#999999999',
            ],
            array_intersect_key(
                $by_points[10]['reference'],
                [
                    'id'    => true,
                    'label' => true,
                ]
            )
        );
        $this->assertStringContainsString( '999999999', $by_points[10]['reference']['url'] );
        $this->assertNull( $by_points[10]['subtotal'] );

        // Comment (review) reference: shaped, never a subtotal.
        $this->assertSame(
            [
                'id'    => 555,
                'label' => '#555',
            ],
            array_intersect_key(
                $by_points[20]['reference'],
                [
                    'id'    => true,
                    'label' => true,
                ]
            )
        );
        $this->assertStringContainsString( 'comment.php', $by_points[20]['reference']['url'] );
        $this->assertStringContainsString( 'editcomment', $by_points[20]['reference']['url'] );
        $this->assertNull( $by_points[20]['subtotal'] );

        // Signup (user) reference: no secondary record to link to.
        $this->assertNull( $by_points[30]['reference'] );
        $this->assertNull( $by_points[30]['subtotal'] );
    }

    public function test_paginate_entries_lists_a_deleted_customers_rows(): void {
        $campaign_id = $this->insert_campaign( CampaignTrigger::PURCHASE );
        $gone        = 987654321;

        $this->assertFalse( get_userdata( $gone ) );

        $this->insert_row(
            $campaign_id,
            [
                'user_id'  => $gone,
                'points'   => 40,
                'order_id' => 7001,
            ]
        );

        $page = $this->repository->paginate_entries( $campaign_id, 1, 10, '' );

        $this->assertSame( $this->repository->totals( $campaign_id )['entries_total'], $page['total'] );
        $this->assertSame( 'Deleted customer #' . $gone, $page['items'][0]['customer_name'] );
    }

    private function insert_campaign( string $trigger ): int {
        static $sequence = 0;
        ++$sequence;

        return ( new CampaignRepository() )->insert(
            new Campaign( null, 'Performance test ' . $sequence, 'performance-test-' . $sequence, $trigger, Status::ACTIVE )
        );
    }

    /**
     * @param array<string, mixed> $overrides user_id, points, order_id (required); reference_type,
     *                                         reference_id, type, created_at (optional).
     */
    private function insert_row( int $campaign_id, array $overrides ): void {
        $reference_type = $overrides['reference_type'] ?? ReferenceType::ORDER;
        $source         = ReferenceType::COMMENT === $reference_type ? LedgerSource::REVIEW : LedgerSource::ORDER;

        $entry = new LedgerEntry(
            null,
            (int) $overrides['user_id'],
            (int) $overrides['points'],
            0,
            $overrides['type'] ?? LedgerType::EARN,
            $source,
            $reference_type,
            (int) ( $overrides['reference_id'] ?? $overrides['order_id'] ),
            $campaign_id,
            null,
            isset( $overrides['order_id'] ) ? $overrides['order_id'] : null,
            null,
            null,
            $overrides['created_at'] ?? Dates::now(),
            // Raw fixture rows, not real awards — null never collides with the UNIQUE index.
            null
        );

        ( new LedgerRepository() )->insert( $entry );
    }
}
