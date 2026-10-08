<?php
namespace YayLoyalty\Services;

use YayLoyalty\Database\Transaction;
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
 * Writes the setup wizard's answers into the built-in campaigns, the built-in reward, the settings and
 * the design, and turns the program on, all or nothing. Rows are written before options: the object
 * cache holds an option the moment it is written, and a rollback cannot take it back.
 * The draft must already have passed SetupHelper::draft_error().
 */
final class SetupApplier {

    private const SIGNUP_SLUG = 'signup';

    private const REVIEW_SLUG = 'review';

    private CampaignRepository $campaigns;

    private RewardRepository $rewards;

    public function __construct( CampaignRepository $campaigns, RewardRepository $rewards ) {
        $this->campaigns = $campaigns;
        $this->rewards   = $rewards;
    }

    /**
     * @param array $draft A SetupHelper draft.
     * @throws \RuntimeException When a built-in is missing or a new config is refused; nothing is written then.
     */
    public function apply( array $draft ): void {
        Transaction::run(
            function () use ( $draft ) {
                $purchase = $this->campaign( CampaignRepository::DEFAULT_PURCHASE_SLUG );
                $signup   = $this->campaign( self::SIGNUP_SLUG );
                $review   = $this->campaign( self::REVIEW_SLUG );
                $reward   = $this->rewards->find_by_slug( RewardRepository::CART_DISCOUNT_SLUG );

                if ( null === $reward ) {
                    throw new \RuntimeException( 'SetupApplier: the built-in reward ' . RewardRepository::CART_DISCOUNT_SLUG . ' is missing.' );
                }

                $max_percent = (int) $draft['reward']['max_percent'];

                $this->write_campaign( $purchase, [ 'points_per_unit' => (int) $draft['earn']['points'] / (float) $draft['earn']['amount'] ], true );
                $this->write_campaign( $signup, [ 'points' => (int) $draft['signup']['points'] ], (bool) $draft['signup']['enabled'] );
                $this->write_campaign( $review, [ 'points' => (int) $draft['review']['points'] ], (bool) $draft['review']['enabled'] );
                $this->write_reward(
                    $reward,
                    [
                        'points_per_currency_unit' => (int) $draft['reward']['points'] / (float) $draft['reward']['amount'],
                        'min_points'               => (int) $draft['reward']['min_points'],
                        // 100% is no cap, which is stored as null, never 100.
                        'max_percent_of_subtotal'  => $max_percent < 100 ? $max_percent : null,
                    ]
                );

                SettingsHelper::update_settings(
                    [
                        'general'  => [ 'enabled' => true ],
                        'advanced' => [
                            'points_label_singular' => $draft['points_label_singular'],
                            'points_label_plural'   => $draft['points_label_plural'],
                        ],
                    ]
                );

                DesignHelper::update_design(
                    [
                        'launcher' => [
                            'position'    => $draft['launcher']['position'],
                            'brand_color' => $draft['launcher']['brand_color'],
                        ],
                    ]
                );
            }
        );
    }

    private function campaign( string $slug ): Campaign {
        $campaign = $this->campaigns->find_by_slug( $slug );

        if ( null === $campaign ) {
            throw new \RuntimeException( 'SetupApplier: the built-in campaign ' . $slug . ' is missing.' );
        }

        return $campaign;
    }

    /**
     * Replaces only $changes in the stored config, so keys the wizard does not ask about keep their values.
     * A key an older version stored and the schema no longer lists is dropped first, as set_config() would.
     */
    private function write_campaign( Campaign $campaign, array $changes, bool $is_on ): void {
        $config = Campaign::validate_config( $campaign->get_trigger(), array_replace( $campaign->get_config(), $changes ) );

        if ( ! Campaign::config_is_valid( $campaign->get_trigger(), $config ) ) {
            throw new \RuntimeException( 'SetupApplier: the config for campaign ' . $campaign->get_slug() . ' was refused.' );
        }

        $campaign->set_config( $config );
        $campaign->set_status( $is_on ? Status::ACTIVE : Status::PAUSED );
        $campaign->set_updated_at( Dates::now() );
        $campaign->set_updated_by( get_current_user_id() );

        $update = $campaign->to_update_row();
        $this->campaigns->update( (int) $campaign->get_id(), $update['data'], $update['format'] );
    }

    /** As write_campaign(): the stored config with $changes, minus keys the schema no longer lists. */
    private function write_reward( Reward $reward, array $changes ): void {
        $config = Reward::validate_config( $reward->get_type(), array_replace( $reward->get_config(), $changes ) );

        if ( ! Reward::config_is_valid( $reward->get_type(), $config ) ) {
            throw new \RuntimeException( 'SetupApplier: the config for reward ' . $reward->get_slug() . ' was refused.' );
        }

        $reward->set_config( $config );
        $reward->set_status( Status::ACTIVE );
        $reward->set_updated_at( Dates::now() );
        $reward->set_updated_by( get_current_user_id() );

        $update = $reward->to_update_row();
        $this->rewards->update( (int) $reward->get_id(), $update['data'], $update['format'] );
    }
}
