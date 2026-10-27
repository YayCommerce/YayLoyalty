<?php
namespace YayLoyalty\Pro\Integrations\YayMail;

use YayLoyalty\Integrations\YayMail\LoyaltyEmailShortcodes;
use YayLoyalty\Integrations\YayMail\LoyaltyYayMailEmail;
use YayLoyalty\Pro\Emails\RewardClaimedEmail;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Reward claimed email's YayMail shortcodes, from RewardClaimedEmail's template values.
 *
 * @method static RewardClaimedShortcodes get_instance()
 */
class RewardClaimedShortcodes extends LoyaltyEmailShortcodes {

    use SingletonTrait;

    protected const VALUE_KEYS = [
        'claimed_points' => 'claimed_text',
        'reward_lines'   => 'reward_lines_html',
        'balance_points' => 'balance_text',
    ];

    protected const HTML_KEYS = [ 'claimed_points', 'reward_lines' ];

    public $available_email_ids = [ RewardClaimedEmail::ID ];

    protected function yaymail_email(): LoyaltyYayMailEmail {
        return RewardClaimedYayMailEmail::get_instance();
    }

    protected function descriptions(): array {
        return [
            'claimed_points' => __( 'Points Used Message', 'yayloyalty' ),
            'reward_lines'   => __( 'Rewards Used', 'yayloyalty' ),
            'points'         => __( 'Points Used', 'yayloyalty' ),
        ] + self::shared_descriptions( 'points_label', 'balance', 'balance_points', 'balance_value', 'balance_value_text', 'order_number' ) + [
            'order_url' => __( 'Order URL', 'yayloyalty' ),
        ];
    }
}
