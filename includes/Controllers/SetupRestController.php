<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Enums\Status;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Helpers\SetupHelper;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Services\SetupApplier;

defined( 'ABSPATH' ) || exit;

/**
 * GET /setup and POST /setup/draft, /setup/complete and /setup/dismiss-first-run.
 * Every route needs only the view capability, by product decision: any YayLoyalty user may finish
 * setup, including the labels and launcher look the Settings routes reserve for manage_settings.
 */
class SetupRestController extends AbstractRestController {

    private CampaignRepository $campaigns;

    private RewardRepository $rewards;

    public function __construct( CampaignRepository $campaigns, RewardRepository $rewards ) {
        $this->campaigns = $campaigns;
        $this->rewards   = $rewards;
    }

    public function get_rest_base(): string {
        return 'setup';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/setup',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_setup' ], $request ),
                'permission_callback' => $this->requires( Capabilities::VIEW ),
            ]
        );

        $writes = [
            '/setup/draft'             => 'save_draft',
            '/setup/complete'          => 'complete',
            '/setup/dismiss-first-run' => 'dismiss_first_run',
        ];

        foreach ( $writes as $route => $method ) {
            register_rest_route(
                self::REST_NAMESPACE,
                $route,
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, $method ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                ]
            );
        }
    }

    /** The state, plus the live values a missing draft is pre-filled from. */
    public function get_setup( \WP_REST_Request $request ) {
        $state = SetupHelper::get_state();

        return [
            'status' => $state['status'],
            'step'   => $state['step'],
            'draft'  => $state['draft'],
            'live'   => $this->live(),
        ];
    }

    public function save_draft( \WP_REST_Request $request ) {
        $step  = $request->get_param( 'step' );
        $draft = $request->get_param( 'draft' );

        if ( ! is_int( $step ) || $step < 1 || $step > SetupHelper::LAST_STEP ) {
            return $this->error_invalid_arguments( __( 'The setup step is out of range.', 'yayloyalty' ) );
        }

        $error = SetupHelper::draft_error( $draft );

        if ( null !== $error ) {
            return $this->error_invalid_arguments( $error );
        }

        return SetupHelper::save_draft( $step, $draft );
    }

    /** Read inside exec_write()'s transaction, so of two clicks only the first applies. */
    public function complete( \WP_REST_Request $request ) {
        if ( SetupHelper::STATUS_COMPLETED === SetupHelper::get_state()['status'] ) {
            return $this->error_conflict( __( 'Setup is already complete.', 'yayloyalty' ) );
        }

        $draft = $request->get_param( 'draft' );
        $error = SetupHelper::draft_error( $draft );

        if ( null !== $error ) {
            return $this->error_invalid_arguments( $error );
        }

        ( new SetupApplier( $this->campaigns, $this->rewards ) )->apply( $draft );
        SetupHelper::mark_completed();

        return [ 'status' => SetupHelper::STATUS_COMPLETED ];
    }

    public function dismiss_first_run( \WP_REST_Request $request ) {
        SetupHelper::dismiss_first_run();

        return [ 'first_run_dismissed' => true ];
    }

    /** The live values the wizard edits. The SPA turns the rates into point/amount pairs. */
    private function live(): array {
        $advanced = SettingsHelper::get_settings()['advanced'];
        $purchase = $this->campaigns->find_by_slug( CampaignRepository::DEFAULT_PURCHASE_SLUG );
        $reward   = $this->rewards->find_by_slug( RewardRepository::CART_DISCOUNT_SLUG );
        $config   = null === $reward ? [] : $reward->get_config();
        $launcher = DesignHelper::get_design()['launcher'];

        return [
            'points_label_singular' => $advanced['points_label_singular'],
            'points_label_plural'   => $advanced['points_label_plural'],
            'purchase_rate'         => null === $purchase ? 1.0 : (float) ( $purchase->get_config()['points_per_unit'] ?? 1 ),
            'signup'                => $this->flat_campaign( 'signup', 100 ),
            'review'                => $this->flat_campaign( 'review', 50 ),
            'reward'                => [
                'points_per_currency_unit' => (float) ( $config['points_per_currency_unit'] ?? 100 ),
                'min_points'               => (int) ( $config['min_points'] ?? 100 ),
                'max_percent_of_subtotal'  => isset( $config['max_percent_of_subtotal'] ) ? (float) $config['max_percent_of_subtotal'] : null,
            ],
            'launcher'              => [
                'position'    => $launcher['position'],
                'brand_color' => $launcher['brand_color'],
            ],
        ];
    }

    /** A signup or review built-in as the wizard shows it; $fallback_points stands in if the row is missing. */
    private function flat_campaign( string $slug, int $fallback_points ): array {
        $campaign = $this->campaigns->find_by_slug( $slug );

        return [
            'enabled' => null === $campaign || Status::ACTIVE === $campaign->get_status(),
            'points'  => $campaign instanceof Campaign ? $campaign->flat_points() : $fallback_points,
        ];
    }
}
