<?php
namespace YayLoyalty\Pro\Emails;

use YayLoyalty\Engine\Emails\LedgerEntryEmail;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The reward-claimed email: one receipt per order that spends points, through the points discount, rewards or
 * both. Pro only. Its lines are the order's `applied` redemption rows, so a deferred send after a cancel sends nothing.
 */
class RewardClaimedEmail extends LedgerEntryEmail {

    public const ID = 'yayloyalty_reward_claimed';

    /** The email action this email listens to; ProEmails owns the name, so naming it never loads WC_Email. */
    public const ACTION = ProEmails::REWARD_CLAIMED_ACTION;

    protected $is_enabled_by_default = false;

    /** A receipt for the customer's own action, so the weekly cap never holds it back. */
    protected $is_capped = false;

    /** @var array|null The preview's sample lines; null outside the preview. */
    private $preview_lines = null;

    /** @var string The preview's sample order number. */
    private $preview_order_number = '';

    /** @var LedgerEntry|null The row $lines were read for. */
    private $lines_entry = null;

    /** @var array<int, array{name: string, points: int, points_text: string, discount: string}> */
    private $lines = [];

    public function __construct() {
        $this->id          = self::ID;
        $this->title       = __( 'Reward claimed', 'yayloyalty' );
        $this->description = __( 'Sent when a customer spends points on an order, through the points discount or a reward.', 'yayloyalty' );
        $this->email_group = ProEmails::GROUP_REWARDS;

        $this->placeholders = [
            '{points}'       => '',
            '{points_label}' => '',
            '{balance}'      => '',
            '{order_number}' => '',
        ];

        add_action( self::ACTION . '_notification', [ $this, 'trigger' ] );

        parent::__construct();
    }

    protected function templates_dir(): string {
        return __DIR__ . '/templates/';
    }

    public function get_default_subject() {
        return __( '[{site_title}]: You used {points} {points_label} on order #{order_number}', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Reward claimed', 'yayloyalty' );
    }

    /** An order's REDEEM row with lines still applied, so a deferred send after a cancel sends nothing. */
    protected function accepts( LedgerEntry $entry ): bool {
        return LedgerType::REDEEM === $entry->get_type()
            && ReferenceType::ORDER === $entry->get_reference_type()
            && null !== $entry->get_reference_id()
            && [] !== $this->lines( $entry );
    }

    /** @return array{points: string, points_label: string, balance: string, order_number: string} */
    protected function amounts( LedgerEntry $entry ): array {
        $points = self::total_points( $this->lines( $entry ) );
        $order  = $this->order( $entry );

        if ( $order ) {
            $order_number = (string) $order->get_order_number();
        } else {
            $order_number = null !== $this->preview_lines ? $this->preview_order_number : '';
        }

        return [
            'points'       => SettingsHelper::format_number( $points ),
            'points_label' => SettingsHelper::points_label( $points ),
            'balance'      => SettingsHelper::format_number( $entry->get_balance_after() ),
            'order_number' => $order_number,
        ];
    }

    /** @return array<string, mixed> */
    protected function build_values( LedgerEntry $entry ): array {
        $values    = $this->balance_values( $entry );
        $order     = $this->order( $entry );
        $order_url = $order ? $order->get_view_order_url() : '';
        $lines     = $this->lines( $entry );

        return $values + [
            'order'             => $order,
            'order_url'         => $order_url,
            'claimed_text'      => self::claimed_text( self::total_points( $lines ), $values['order_number'], $order_url ),
            'reward_lines'      => $lines,
            'reward_lines_html' => self::lines_html( $lines ),
        ];
    }

    /** Previews have no real order, so they show two made-up lines, greeting whoever is looking. Reads no database rows. */
    public function prepare_preview(): void {
        $currency = [ 'currency' => get_option( 'woocommerce_currency' ) ];

        $this->preview_lines        = [
            [
                'name'        => __( 'Points discount', 'yayloyalty' ),
                'points'      => 500,
                'points_text' => SettingsHelper::points_text( 500 ),
                'discount'    => wc_price( 5.00, $currency ),
            ],
            [
                'name'        => __( 'Free gift reward', 'yayloyalty' ),
                'points'      => 1000,
                'points_text' => SettingsHelper::points_text( 1000 ),
                'discount'    => wc_price( 10.00, $currency ),
            ],
        ];
        $this->preview_order_number = '1234';

        $this->prepare( new LedgerEntry( null, get_current_user_id(), -1500, 300, LedgerType::REDEEM, LedgerSource::ORDER, ReferenceType::ORDER, 0 ) );
    }

    /**
     * One line per `applied` redemption row on the entry's order, oldest first. Read once per row: accepts(),
     * amounts() and build_values() each ask.
     *
     * @return array<int, array{name: string, points: int, points_text: string, discount: string}>
     */
    private function lines( LedgerEntry $entry ): array {
        if ( null !== $this->preview_lines ) {
            return $this->preview_lines;
        }

        if ( $this->lines_entry === $entry ) {
            return $this->lines;
        }

        $rewards = new RewardRepository();
        $lines   = [];

        foreach ( ( new RedemptionRepository() )->for_order( (int) $entry->get_reference_id() ) as $row ) {
            if ( Redemption::STATUS_APPLIED !== $row->get_status() ) {
                continue;
            }

            $reward = $rewards->find_reward( $row->get_reward_id() );

            if ( ! $reward ) {
                $name = __( 'Reward', 'yayloyalty' );
            } elseif ( RewardType::FLEXIBLE_DISCOUNT === $reward->get_type() ) {
                $name = __( 'Points discount', 'yayloyalty' );
            } else {
                $name = $reward->get_name();
            }

            $lines[] = [
                'name'        => $name,
                'points'      => $row->get_points(),
                'points_text' => SettingsHelper::points_text( $row->get_points() ),
                // The order's currency: the amount the customer saw at checkout.
                'discount'    => wc_price( (float) $row->get_value(), [ 'currency' => $row->get_currency() ] ),
            ];
        }

        $this->lines_entry = $entry;
        $this->lines       = $lines;

        return $lines;
    }

    /** The entry's order, or null when it is gone or while the preview runs. */
    private function order( LedgerEntry $entry ): ?\WC_Order {
        if ( null !== $this->preview_lines ) {
            return null;
        }

        $order = wc_get_order( (int) $entry->get_reference_id() );

        return $order instanceof \WC_Order ? $order : null;
    }

    /** @param array<int, array{points: int}> $lines */
    private static function total_points( array $lines ): int {
        return (int) array_sum( array_column( $lines, 'points' ) );
    }

    /** Escaped HTML with the points in bold, and the order number linked when there is a URL. */
    private static function claimed_text( int $points, string $order_number, string $order_url ): string {
        $points_html = '<strong>' . esc_html( SettingsHelper::points_text( $points ) ) . '</strong>';

        if ( '' === $order_number ) {
            /* translators: %s: points with label, e.g. "500 Points". */
            return sprintf( esc_html__( 'You used %s on your order.', 'yayloyalty' ), $points_html );
        }

        if ( '' === $order_url ) {
            /* translators: 1: points with label, e.g. "500 Points", 2: order number, e.g. "#1234". */
            return sprintf( esc_html__( 'You used %1$s on order %2$s.', 'yayloyalty' ), $points_html, esc_html( '#' . $order_number ) );
        }

        $order_link = '<a href="' . esc_url( $order_url ) . '">' . esc_html( '#' . $order_number ) . '</a>';

        /* translators: 1: points with label, e.g. "500 Points", 2: order number linked to the order, e.g. "#1234". */
        return sprintf( esc_html__( 'You used %1$s on order %2$s.', 'yayloyalty' ), $points_html, $order_link );
    }

    /**
     * The lines as an email table: name, points and discount per row. Escaped when built; the discount is wc_price() HTML.
     *
     * @param array<int, array{name: string, points_text: string, discount: string}> $lines
     */
    private static function lines_html( array $lines ): string {
        $rows = '';

        foreach ( $lines as $line ) {
            /* translators: %s: discount amount, e.g. "$5.00". */
            $discount = sprintf( __( '−%s', 'yayloyalty' ), $line['discount'] );

            $rows .= '<tr><td>' . esc_html( $line['name'] ) . '</td><td>' . esc_html( $line['points_text'] ) . '</td><td>' . $discount . '</td></tr>';
        }

        return '<table class="yayloyalty-reward-lines" cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;">' . $rows . '</table>';
    }
}
