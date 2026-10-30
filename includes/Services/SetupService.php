<?php
namespace YayLoyalty\Services;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The setup wizard's writes. complete() works on rows and options that already exist:
 * the settings and design options, the three built-in campaigns and the cart discount the installer seeds.
 * The wizard edits a few config keys of each row, so its values are merged over the stored config
 * and every key it never shows survives. dismiss() only marks setup done.
 */
final class SetupService {

    /** @var CampaignRepository */
    private $campaigns;

    /** @var RewardRepository */
    private $rewards;

    /** @var array{purchase: ?Campaign, signup: ?Campaign, review: ?Campaign, discount: ?Reward}|null Loaded once per write: validate, save and the response share them. */
    private $rows = null;

    public function __construct( CampaignRepository $campaigns, RewardRepository $rewards ) {
        $this->campaigns = $campaigns;
        $this->rewards   = $rewards;
    }

    /** @return array<string, mixed> The setup payload from the rows already loaded. */
    private function payload(): array {
        $settings = SettingsHelper::get_settings();
        $design   = DesignHelper::get_design();
        $rows     = $this->rows();

        $purchase = ( $rows['purchase'] ? $rows['purchase']->get_config() : [] ) + Campaign::default_config( CampaignTrigger::PURCHASE );
        $discount = ( $rows['discount'] ? $rows['discount']->get_config() : [] ) + Reward::default_config( RewardType::FLEXIBLE_DISCOUNT );

        return [
            'points_label_singular' => $settings['points_label_singular'],
            'points_label_plural'   => $settings['points_label_plural'],
            'purchase'              => [ 'points_per_unit' => $purchase['points_per_unit'] ],
            'signup'                => self::toggle_of( $rows['signup'] ),
            'review'                => self::toggle_of( $rows['review'] ),
            'flexible_discount'     => [
                'points_per_currency_unit'    => $discount['points_per_currency_unit'],
                'min_points'                  => $discount['min_points'],
                'max_percent_of_discountable' => $discount['max_percent_of_discountable'] ?? null,
            ],
            'brand_color'           => $design['launcher']['brand_color'] ?? '',
        ];
    }

    /** Why $input must be refused before anything is written, or null when it may be saved. */
    public function validation_error( array $input ): ?string {
        // Fresh rows for this write; complete() and its response reuse them.
        $this->rows = null;
        $rows       = $this->rows();

        if ( in_array( null, $rows, true ) ) {
            return __( 'A built-in campaign or reward is missing. Deactivate and reactivate YayLoyalty to restore it.', 'yayloyalty' );
        }

        $configs = $this->merged_configs( $rows, $input );

        foreach ( [ 'purchase', 'signup', 'review' ] as $key ) {
            if ( ! Campaign::config_is_valid( $rows[ $key ]->get_trigger(), $configs[ $key ] ) ) {
                return __( 'One of the earning values is not a valid number.', 'yayloyalty' );
            }
        }

        if ( ! Reward::config_is_valid( RewardType::FLEXIBLE_DISCOUNT, $configs['discount'] ) ) {
            return __( 'One of the redemption values is not a valid number.', 'yayloyalty' );
        }

        $conflict = Reward::config_conflict( RewardType::FLEXIBLE_DISCOUNT, $configs['discount'] );

        if ( null !== $conflict ) {
            return $conflict;
        }

        if ( ! sanitize_hex_color( (string) ( $input['brand_color'] ?? '' ) ) ) {
            return __( 'The brand colour must be a hex colour such as #3858e9.', 'yayloyalty' );
        }

        return null;
    }

    /**
     * Saves every wizard choice and marks setup done. validation_error() must have returned null.
     *
     * @return array<string, mixed> The saved values, built from the rows just saved.
     */
    public function complete( array $input ): array {
        $rows    = $this->rows();
        $configs = $this->merged_configs( $rows, $input );

        foreach ( [ 'purchase', 'signup', 'review' ] as $key ) {
            // The purchase rate has no switch in the wizard, so its status is left as it is.
            if ( 'purchase' !== $key ) {
                $rows[ $key ]->set_status( ! empty( $input[ $key ]['enabled'] ) ? Status::ACTIVE : Status::PAUSED );
            }

            $this->save_row( $this->campaigns, $rows[ $key ], $configs[ $key ] );
        }

        $this->save_row( $this->rewards, $rows['discount'], $configs['discount'] );

        SettingsHelper::update_settings(
            [
                'points_label_singular' => (string) ( $input['points_label_singular'] ?? '' ),
                'points_label_plural'   => (string) ( $input['points_label_plural'] ?? '' ),
                'enabled'               => true,
            ]
        );

        DesignHelper::update_design( [ 'launcher' => [ 'brand_color' => (string) $input['brand_color'] ] ] );

        $this->dismiss();

        return $this->payload();
    }

    /** Marks setup done, so the wizard never opens again. Alone, it is "Exit setup": nothing else changes. */
    public function dismiss(): void {
        update_option( SettingsHelper::OPTION_SETUP_COMPLETED, true );
    }

    /** @return array{purchase: ?Campaign, signup: ?Campaign, review: ?Campaign, discount: ?Reward} */
    private function rows(): array {
        if ( null === $this->rows ) {
            $this->rows = [
                'purchase' => $this->campaigns->find_by_slug( CampaignRepository::DEFAULT_PURCHASE_SLUG ),
                'signup'   => $this->campaigns->find_by_slug( CampaignRepository::SIGNUP_SLUG ),
                'review'   => $this->campaigns->find_by_slug( CampaignRepository::REVIEW_SLUG ),
                'discount' => $this->rewards->find_by_slug( RewardRepository::FLEXIBLE_DISCOUNT_SLUG ),
            ];
        }

        return $this->rows;
    }

    /**
     * Each row's stored config with the wizard's keys laid over it.
     *
     * @return array{purchase: array, signup: array, review: array, discount: array}
     */
    private function merged_configs( array $rows, array $input ): array {
        $discount = (array) ( $input['flexible_discount'] ?? [] );

        return [
            'purchase' => array_merge( $rows['purchase']->get_config(), [ 'points_per_unit' => $input['purchase']['points_per_unit'] ?? null ] ),
            'signup'   => array_merge( $rows['signup']->get_config(), [ 'points' => $input['signup']['points'] ?? null ] ),
            'review'   => array_merge( $rows['review']->get_config(), [ 'points' => $input['review']['points'] ?? null ] ),
            'discount' => array_merge(
                $rows['discount']->get_config(),
                [
                    'points_per_currency_unit'    => $discount['points_per_currency_unit'] ?? null,
                    'min_points'                  => $discount['min_points'] ?? null,
                    // Absent and null both mean no limit.
                    'max_percent_of_discountable' => $discount['max_percent_of_discountable'] ?? null,
                ]
            ),
        ];
    }

    /** @param Campaign|Reward $row */
    private function save_row( $repository, $row, array $config ): void {
        $row->set_config( $config );
        $row->set_updated_at( Dates::now() );
        $row->set_updated_by( get_current_user_id() );

        $update = $row->to_update_row();
        $repository->update( (int) $row->get_id(), $update['data'], $update['format'] );
    }

    /** @return array{enabled: bool, points: int} */
    private static function toggle_of( ?Campaign $campaign ): array {
        return [
            'enabled' => null !== $campaign && Status::ACTIVE === $campaign->get_status(),
            'points'  => null !== $campaign ? $campaign->flat_points() : 0,
        ];
    }
}
