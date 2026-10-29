<?php
namespace YayLoyalty\Pro\Emails;

use YayLoyalty\Engine\Emails\LedgerEntryEmail;
use YayLoyalty\Engine\Redemption\CouponProvider;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
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

    /** @var LedgerEntry|null The row $claim was read for. */
    private $claim_entry = null;

    /** @var array{lines: array<int, array{name: string, points: int, points_text: string, discount: string}>, order: \WC_Order|null, order_number: string} */
    private $claim = [];

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
            && [] !== $this->claim( $entry )['lines'];
    }

    /** @return array{points: string, points_label: string, balance: string, order_number: string} */
    protected function placeholder_values( LedgerEntry $entry ): array {
        $claim  = $this->claim( $entry );
        $points = self::total_points( $claim['lines'] );

        return [
            'points'       => SettingsHelper::format_number( $points ),
            'points_label' => SettingsHelper::points_label( $points ),
            'balance'      => SettingsHelper::format_number( $entry->get_balance_after() ),
            'order_number' => $claim['order_number'],
        ];
    }

    /** @return array<string, mixed> */
    protected function build_values( LedgerEntry $entry ): array {
        $claim     = $this->claim( $entry );
        $order_url = $claim['order'] ? $claim['order']->get_view_order_url() : '';

        return $this->shared_values( $entry ) + [
            'order'             => $claim['order'],
            'order_url'         => $order_url,
            'claimed_text'      => self::claimed_text( self::total_points( $claim['lines'] ), $claim['order_number'], $order_url ),
            'reward_lines'      => $claim['lines'],
            'reward_lines_html' => self::lines_html( $claim['lines'] ),
        ];
    }

    /**
     * Previews have no real order, so they show two made-up lines and order number, greeting whoever is looking.
     * The sample is seeded as the made-up row's claim, so the preview reads no database rows.
     */
    public function prepare_preview(): void {
        $currency = [ 'currency' => get_option( 'woocommerce_currency' ) ];
        $entry    = new LedgerEntry( null, get_current_user_id(), -1500, 300, LedgerType::REDEEM, LedgerSource::ORDER, ReferenceType::ORDER, 0 );

        $this->claim_entry = $entry;
        $this->claim       = [
            'lines'        => [
                [
                    'name'        => CouponProvider::display_label(),
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
            ],
            'order'        => null,
            'order_number' => '1234',
        ];

        $this->prepare( $entry );
    }

    /**
     * What the entry's order claimed: one line per `applied` redemption row, oldest first, and the order, null when
     * it is gone. Read once per row: accepts(), placeholder_values() and build_values() each ask.
     *
     * @return array{lines: array<int, array{name: string, points: int, points_text: string, discount: string}>, order: \WC_Order|null, order_number: string}
     */
    private function claim( LedgerEntry $entry ): array {
        if ( $this->claim_entry === $entry ) {
            return $this->claim;
        }

        $order_id = (int) $entry->get_reference_id();
        $order    = wc_get_order( $order_id );
        $order    = $order instanceof \WC_Order ? $order : null;
        $rewards  = new RewardRepository();
        $lines    = [];

        foreach ( ( new RedemptionRepository() )->for_order( $order_id ) as $row ) {
            if ( Redemption::STATUS_APPLIED !== $row->get_status() ) {
                continue;
            }

            $reward = $rewards->find_reward( $row->get_reward_id() );

            $name = $reward ? $reward->get_name() : __( 'Reward', 'yayloyalty' );

            $lines[] = [
                'name'        => $name,
                'points'      => $row->get_points(),
                'points_text' => SettingsHelper::points_text( $row->get_points() ),
                // The order's currency: the amount the customer saw at checkout.
                'discount'    => wc_price( (float) $row->get_value(), [ 'currency' => $row->get_currency() ] ),
            ];
        }

        $this->claim_entry = $entry;
        $this->claim       = [
            'lines'        => $lines,
            'order'        => $order,
            'order_number' => $order ? (string) $order->get_order_number() : '',
        ];

        return $this->claim;
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

        $order_html = esc_html( '#' . $order_number );

        if ( '' !== $order_url ) {
            $order_html = '<a href="' . esc_url( $order_url ) . '">' . $order_html . '</a>';
        }

        /* translators: 1: points with label, e.g. "500 Points", 2: order number, linked to the order when it has a URL, e.g. "#1234". */
        return sprintf( esc_html__( 'You used %1$s on order %2$s.', 'yayloyalty' ), $points_html, $order_html );
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

        return '<table class="yayloyalty-reward-lines" cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin-bottom:16px;">' . $rows . '</table>';
    }
}
