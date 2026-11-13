<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Pro\Services\ConditionsEvaluator;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Utils\ConditionGroups;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Explains why a customer does or does not match a campaign's conditions,
 * through the same evaluator the award path uses. There is no order here,
 * so order-scoped conditions report `needs_order`. POST despite being
 * read-only: a question about a specific customer should not sit in a URL
 * or a server log.
 */
final class CampaignPreviewRestController extends AbstractRestController {

    /** @var CampaignRepository */
    private $campaigns;

    /** @var ConditionsEvaluator */
    private $evaluator;

    public function __construct( CampaignRepository $campaigns, ConditionsEvaluator $evaluator ) {
        $this->campaigns = $campaigns;
        $this->evaluator = $evaluator;
    }

    /** A sub-route of /campaigns, not a resource of its own. */
    public function get_rest_base(): string {
        return 'campaigns';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/campaigns/(?P<id>[\d]+)/preview-conditions',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'preview' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                    'args'                => [
                        'user_id' => $this->user_id_param(),
                    ],
                ],
            ]
        );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function preview( \WP_REST_Request $request ) {
        $campaign = $this->campaigns->find_campaign( (int) $request['id'] );

        if ( null === $campaign ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        $user_id = (int) $request['user_id'];

        // No conditions means everyone qualifies, as in PointsEngine::conditions_match().
        if ( ! $campaign->has_conditions() ) {
            return [
                'campaign_id' => (int) $campaign->get_id(),
                'user_id'     => $user_id,
                'matches'     => true,
                'match'       => ConditionGroups::MATCH_ALL,
                'groups'      => [],
            ];
        }

        // Facts as of now: every paid order so far counts.
        $report = $this->evaluator->explain( $campaign->get_conditions(), ContextFactory::from_user( $user_id, Dates::now() ) );

        return [
            'campaign_id' => (int) $campaign->get_id(),
            'user_id'     => $user_id,
            // The evaluator's own verdict, so verdict and explanation cannot disagree.
            'matches'     => $report['matched'],
            'match'       => $report['match'],
            'groups'      => $report['groups'],
        ];
    }

    /**
     * No item schema: this returns a report, not a resource.
     *
     * @return array<string, mixed>
     */
    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_campaign_conditions_preview',
            'type'       => 'object',
            'properties' => [],
        ];
    }
}
