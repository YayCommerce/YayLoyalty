<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Pro\Services\ConditionsEvaluator;
use YayLoyalty\Repositories\CampaignRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Explains why a customer does or does not match a campaign's conditions.
 * POST despite being read-only: a question about a specific customer should
 * not sit in a URL or a server log.
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

        $user_id    = (int) $request['user_id'];
        $conditions = $campaign->get_conditions();

        // No conditions means everyone qualifies, as in PointsEngine::conditions_match().
        if ( empty( $conditions ) ) {
            return [
                'campaign_id' => (int) $campaign->get_id(),
                'user_id'     => $user_id,
                'matches'     => true,
                'conditions'  => [],
            ];
        }

        $results = $this->evaluator->explain( $conditions, $user_id );

        return [
            'campaign_id' => (int) $campaign->get_id(),
            'user_id'     => $user_id,
            // Folded from the same results, so verdict and explanation cannot disagree.
            'matches'     => ! in_array( false, array_column( $results, 'matched' ), true ),
            'conditions'  => $results,
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
