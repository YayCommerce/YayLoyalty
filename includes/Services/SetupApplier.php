<?php
namespace YayLoyalty\Services;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Enums\Status;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\AbstractModel;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\AbstractRepository;
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
                $signup   = $this->campaign( CampaignRepository::SIGNUP_SLUG );
                $review   = $this->campaign( CampaignRepository::REVIEW_SLUG );
                $reward   = $this->rewards->find_by_slug( RewardRepository::CART_DISCOUNT_SLUG );

                if ( null === $reward ) {
                    throw new \RuntimeException( 'SetupApplier: the built-in reward ' . RewardRepository::CART_DISCOUNT_SLUG . ' is missing.' );
                }

                $max_percent = (int) $draft['reward']['max_percent'];

                $this->write( $purchase, $this->campaigns, [ 'points_per_unit' => (int) $draft['earn']['points'] / (float) $draft['earn']['amount'] ], true );
                $this->write( $signup, $this->campaigns, [ 'points' => (int) $draft['signup']['points'] ], (bool) $draft['signup']['enabled'] );
                $this->write( $review, $this->campaigns, [ 'points' => (int) $draft['review']['points'] ], (bool) $draft['review']['enabled'] );
                $this->write(
                    $reward,
                    $this->rewards,
                    [
                        'points_per_currency_unit' => (int) $draft['reward']['points'] / (float) $draft['reward']['amount'],
                        'min_points'               => (int) $draft['reward']['min_points'],
                        // 100% is no cap, which is stored as null, never 100.
                        'max_percent_of_subtotal'  => $max_percent < 100 ? $max_percent : null,
                    ],
                    true
                );

                SettingsHelper::update_settings(
                    [
                        'enabled'               => true,
                        'points_label_singular' => $draft['points_label_singular'],
                        'points_label_plural'   => $draft['points_label_plural'],
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
     * Writes one built-in campaign or reward: the stored config with only $changes replaced, so keys the
     * wizard does not ask about keep their values, and a key an older version stored that the schema no
     * longer lists dropped first, as set_config() would.
     *
     * @param Campaign|Reward $model
     * @throws \RuntimeException When the merged config is refused.
     */
    private function write( AbstractModel $model, AbstractRepository $repository, array $changes, bool $is_on ): void {
        $class  = get_class( $model );
        $kind   = $model instanceof Campaign ? $model->get_trigger() : $model->get_type();
        $config = $class::validate_config( $kind, array_replace( $model->get_config(), $changes ) );

        if ( ! $class::config_is_valid( $kind, $config ) ) {
            throw new \RuntimeException( 'SetupApplier: the config for ' . $model->get_slug() . ' was refused.' );
        }

        $model->set_config( $config );
        $model->set_status( $is_on ? Status::ACTIVE : Status::PAUSED );
        $model->set_updated_at( Dates::now() );
        $model->set_updated_by( get_current_user_id() );

        $update = $model->to_update_row();
        $repository->update( (int) $model->get_id(), $update['data'], $update['format'] );
    }
}
