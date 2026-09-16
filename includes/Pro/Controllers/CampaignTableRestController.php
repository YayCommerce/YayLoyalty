<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;

defined( 'ABSPATH' ) || exit;

/**
 * Pro-only: the prioritised, grouped campaign table for the Campaigns (Pro)
 * screen — see apps/admin/src/pro/campaigns/CampaignTable.tsx.
 *
 * SWAP POINT: fixture() below is a hardcoded array, no CampaignRepository, no
 * $wpdb. Once the grouped/priority view is a real feature, replace fixture()
 * with a repository-backed read; get_items() and everything else stays.
 *
 * Registered through `yayloyalty_rest_controllers`, so Lite needs no
 * knowledge of it — same seam CampaignPreviewRestController uses, and for the
 * same reason: Lite has no Pro campaigns to group.
 */
final class CampaignTableRestController extends AbstractRestController {

    /**
     * Shares the base of the resource it reports on, like
     * CampaignPreviewRestController — this is a view over campaigns, not a
     * resource of its own.
     */
    public function get_rest_base(): string {
        return 'campaigns';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/campaigns/table',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_items' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                ],
            ]
        );
    }

    /**
     * @return array{data: array<int, array<string, mixed>>}
     */
    public function get_items(): array {
        return [ 'data' => $this->fixture() ];
    }

    /**
     * The original seven campaigns across four trigger groups, matching the
     * Campaigns (Pro) wireframe, plus one archived campaign and fourteen
     * more (ids 9-22) padding the purchase, signup, review and other groups
     * — see the comment at id 9 for why. `display_status` is
     * presentation-only — unlike the real `status` column it includes
     * "scheduled", which today is derived client-side from status + date
     * window and has no fixture dates to derive it from here.
     *
     * ids 4-6 are the three built-ins — their name/slug must match
     * Installer::seed_builtins() exactly (is_builtin rows are matched by
     * slug across the app), since these are the same rows the Lite build
     * edits, before and after a Pro upgrade.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fixture(): array {
        return [
            [
                'id'                  => 1,
                'name'                => 'Summit+ members 1.5×',
                'slug'                => 'summit-plus-members-1-5x',
                'trigger'             => 'purchase',
                'gives'               => '×1.5 points',
                'conditions_label'    => 'Tier is Summit or above',
                'schedule_label'      => 'Always on',
                'priority'            => 100,
                'display_status'      => 'active',
                'is_builtin'          => false,
                'points_last_30_days' => 38600,
            ],
            [
                'id'                  => 2,
                'name'                => 'Black Friday 2×',
                'slug'                => 'black-friday-2x',
                'trigger'             => 'purchase',
                'gives'               => '×2 points',
                'conditions_label'    => 'No conditions',
                'schedule_label'      => '27–30 Nov 2026',
                'priority'            => 90,
                'display_status'      => 'scheduled',
                'is_builtin'          => false,
                'points_last_30_days' => null,
            ],
            [
                'id'                  => 3,
                'name'                => 'Trail Shoes boost',
                'slug'                => 'trail-shoes-boost',
                'trigger'             => 'purchase',
                'gives'               => '3 points per $1',
                'conditions_label'    => 'Category is Trail Shoes',
                'schedule_label'      => 'Always on',
                'priority'            => 80,
                'display_status'      => 'paused',
                'is_builtin'          => false,
                'points_last_30_days' => null,
            ],
            [
                'id'                  => 4,
                'name'                => 'Points for purchases',
                'slug'                => 'default-purchase',
                'trigger'             => 'purchase',
                'gives'               => '1 point per $1',
                'conditions_label'    => 'No conditions',
                'schedule_label'      => 'Always on',
                'priority'            => 0,
                'display_status'      => 'active',
                'is_builtin'          => true,
                'points_last_30_days' => 121400,
            ],
            [
                'id'                  => 5,
                'name'                => 'Points for signing up',
                'slug'                => 'signup',
                'trigger'             => 'signup',
                'gives'               => '100 points',
                'conditions_label'    => 'Once per customer',
                'schedule_label'      => 'Always on',
                'priority'            => 0,
                'display_status'      => 'active',
                'is_builtin'          => true,
                'points_last_30_days' => 62150,
            ],
            [
                'id'                  => 6,
                'name'                => 'Points for writing a review',
                'slug'                => 'review',
                'trigger'             => 'review',
                'gives'               => '50 points',
                'conditions_label'    => 'Verified purchases only',
                'schedule_label'      => 'Always on',
                'priority'            => 0,
                'display_status'      => 'active',
                'is_builtin'          => true,
                'points_last_30_days' => 9300,
            ],
            [
                'id'                  => 7,
                'name'                => 'Birthday points',
                'slug'                => 'birthday-points',
                'trigger'             => 'birthday',
                'gives'               => '500 points',
                'conditions_label'    => 'Tier is Alpine',
                'schedule_label'      => 'Yearly, on the date',
                'priority'            => 0,
                'display_status'      => 'draft',
                'is_builtin'          => false,
                'points_last_30_days' => null,
            ],
            [
                'id'                  => 8,
                'name'                => 'Spring Sale 1.5×',
                'slug'                => 'spring-sale-1-5x',
                'trigger'             => 'purchase',
                'gives'               => '×1.5 points',
                'conditions_label'    => 'No conditions',
                'schedule_label'      => '1–15 Mar 2026',
                'priority'            => 5,
                'display_status'      => 'archived',
                'is_builtin'          => false,
                'points_last_30_days' => null,
            ],
            // --- Padding below: purpose-built to exercise three things the
            // Pro table's grouped list has to handle once a store has been
            // running a while — a priority tie, a group large enough to
            // collapse, and a total library large enough to need search.
            [
                'id'                  => 9,
                'name'                => 'VIP double points',
                'slug'                => 'vip-double-points',
                'trigger'             => 'purchase',
                'gives'               => '×2 points',
                'conditions_label'    => 'Tier is VIP',
                'schedule_label'      => 'Always on',
                // Ties Trail Shoes boost (id 3) on purpose — see the
                // "Priority tie" badge in CampaignTable.tsx.
                'priority'            => 80,
                'display_status'      => 'active',
                'is_builtin'          => false,
                // Above a million on purpose — see formatPoints()'s abbreviation.
                'points_last_30_days' => 1240000,
            ],
            [
                'id'                  => 10,
                'name'                => 'Loyalty week 1.2×',
                'slug'                => 'loyalty-week-1-2x',
                'trigger'             => 'purchase',
                'gives'               => '×1.2 points',
                'conditions_label'    => 'No conditions',
                'schedule_label'      => '1–7 Dec 2026',
                'priority'            => 35,
                'display_status'      => 'active',
                'is_builtin'          => false,
                'points_last_30_days' => 5200,
            ],
            [
                'id'                  => 11,
                'name'                => 'Newsletter bonus',
                'slug'                => 'newsletter-bonus',
                'trigger'             => 'purchase',
                'gives'               => '2 points per $1',
                'conditions_label'    => 'Subscribed to the newsletter',
                'schedule_label'      => 'Always on',
                'priority'            => 25,
                'display_status'      => 'active',
                'is_builtin'          => false,
                'points_last_30_days' => 3100,
            ],
            [
                'id'                  => 12,
                'name'                => 'Weekend flash 1.3×',
                'slug'                => 'weekend-flash-1-3x',
                'trigger'             => 'purchase',
                'gives'               => '×1.3 points',
                'conditions_label'    => 'No conditions',
                'schedule_label'      => 'Always on',
                'priority'            => 15,
                'display_status'      => 'paused',
                'is_builtin'          => false,
                'points_last_30_days' => null,
            ],
            [
                'id'                  => 13,
                'name'                => 'Clearance category boost',
                'slug'                => 'clearance-category-boost',
                'trigger'             => 'purchase',
                'gives'               => '2 points per $1',
                'conditions_label'    => 'Category is Clearance',
                'schedule_label'      => 'Always on',
                'priority'            => 5,
                'display_status'      => 'draft',
                'is_builtin'          => false,
                'points_last_30_days' => null,
            ],
            [
                'id'                  => 14,
                'name'                => 'Welcome bonus boost',
                'slug'                => 'welcome-bonus-boost',
                'trigger'             => 'signup',
                'gives'               => '50 points',
                'conditions_label'    => 'First-time signups only',
                'schedule_label'      => 'Always on',
                'priority'            => 10,
                'display_status'      => 'active',
                'is_builtin'          => false,
                'points_last_30_days' => 1200,
            ],
            [
                'id'                  => 15,
                'name'                => 'Referral signup bonus',
                'slug'                => 'referral-signup-bonus',
                'trigger'             => 'signup',
                'gives'               => '25 points',
                'conditions_label'    => 'Came from a referral link',
                'schedule_label'      => 'Always on',
                'priority'            => 5,
                'display_status'      => 'active',
                'is_builtin'          => false,
                'points_last_30_days' => 800,
            ],
            [
                'id'                  => 16,
                'name'                => 'Photo review bonus',
                'slug'                => 'photo-review-bonus',
                'trigger'             => 'review',
                'gives'               => '20 points',
                'conditions_label'    => 'Review includes a photo',
                'schedule_label'      => 'Always on',
                'priority'            => 10,
                'display_status'      => 'active',
                'is_builtin'          => false,
                'points_last_30_days' => 640,
            ],
            [
                'id'                  => 17,
                'name'                => 'Video review bonus',
                'slug'                => 'video-review-bonus',
                'trigger'             => 'review',
                'gives'               => '75 points',
                'conditions_label'    => 'Review includes a video',
                'schedule_label'      => 'Always on',
                'priority'            => 5,
                'display_status'      => 'paused',
                'is_builtin'          => false,
                'points_last_30_days' => null,
            ],
            [
                'id'                  => 18,
                'name'                => 'First order bonus',
                'slug'                => 'first-order-bonus',
                'trigger'             => 'first_order',
                'gives'               => '150 points',
                'conditions_label'    => 'No conditions',
                'schedule_label'      => 'Always on',
                'priority'            => 5,
                'display_status'      => 'active',
                'is_builtin'          => false,
                'points_last_30_days' => 4200,
            ],
            [
                'id'                  => 19,
                'name'                => 'Frequent buyer reward',
                'slug'                => 'frequent-buyer-reward',
                'trigger'             => 'order_count',
                'gives'               => '200 points',
                'conditions_label'    => '5th order or more',
                'schedule_label'      => 'Always on',
                'priority'            => 10,
                'display_status'      => 'active',
                'is_builtin'          => false,
                'points_last_30_days' => 980,
            ],
            [
                'id'                  => 20,
                'name'                => 'Big spender milestone',
                'slug'                => 'big-spender-milestone',
                'trigger'             => 'spend_milestone',
                'gives'               => '1000 points',
                'conditions_label'    => 'Lifetime spend over $500',
                'schedule_label'      => '1 Jan 2027',
                'priority'            => 15,
                'display_status'      => 'scheduled',
                'is_builtin'          => false,
                'points_last_30_days' => null,
            ],
            [
                'id'                  => 21,
                'name'                => 'Anniversary bonus',
                'slug'                => 'anniversary-bonus',
                'trigger'             => 'birthday',
                'gives'               => '300 points',
                'conditions_label'    => 'Tier is Summit or above',
                'schedule_label'      => 'Yearly, on signup date',
                'priority'            => 20,
                'display_status'      => 'draft',
                'is_builtin'          => false,
                'points_last_30_days' => null,
            ],
            [
                'id'                  => 22,
                'name'                => 'Tier upgrade bonus',
                'slug'                => 'tier-upgrade-bonus',
                'trigger'             => 'spend_milestone',
                'gives'               => '500 points',
                'conditions_label'    => 'Reaches Summit tier',
                'schedule_label'      => 'Always on',
                'priority'            => 25,
                'display_status'      => 'active',
                'is_builtin'          => false,
                'points_last_30_days' => 2100,
            ],
        ];
    }

    /**
     * No item schema: like CampaignPreviewRestController, this route returns
     * a report over campaigns, not a writable resource.
     *
     * @return array<string, mixed>
     */
    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_campaign_table',
            'type'       => 'object',
            'properties' => [],
        ];
    }
}
