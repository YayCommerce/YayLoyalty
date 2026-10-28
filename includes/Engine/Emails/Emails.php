<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Expiry\ExpiryPolicy;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/** Registers the Lite loyalty emails and their group with WooCommerce. */
class Emails {

    use SingletonTrait;

    public const GROUP_POINTS = 'yayloyalty_points';

    /** The email action an award is relayed on. Its only argument is the ledger row id. */
    public const POINTS_EARNED_ACTION = 'yayloyalty_points_earned_email';

    /** The email action a manual adjustment is relayed on, when its admin asked to notify. Its only argument is the ledger row id. */
    public const POINTS_ADJUSTED_ACTION = 'yayloyalty_points_adjusted_email';

    /** The Points expired email's id. Here, not only on the email class, so the expiry job can queue it before WC_Email loads. */
    public const POINTS_EXPIRED_EMAIL_ID = 'yayloyalty_points_expired';

    /** The Points expiring soon email's id, here for the same reason. */
    public const POINTS_EXPIRING_SOON_EMAIL_ID = 'yayloyalty_points_expiring_soon';

    /** Fired after the transport accepted an expiry reminder, for the activity log. Args: user id, points, expires_at, window days. */
    public const POINTS_EXPIRING_SOON_SENT_ACTION = 'yayloyalty_points_expiring_soon_email_sent';

    protected function __construct() {
        add_filter( 'woocommerce_email_classes', [ $this, 'add_classes' ] );
        add_filter( 'woocommerce_email_groups', [ $this, 'add_groups' ] );
        add_filter( 'woocommerce_email_actions', [ $this, 'add_email_actions' ] );
        add_action( EarningService::AWARDED_ACTION, [ $this, 'relay_award' ] );
        add_filter( 'woocommerce_prepare_email_for_preview', [ $this, 'prepare_preview' ] );
        ( new QueuedEmails() )->register();
    }

    /**
     * WooCommerce builds the mailer before it fires {action}_notification, so each email's listener exists.
     * No email class is named here: WooCommerce reads this list on init, before WC_Email loads.
     *
     * @param string[] $actions
     * @return string[]
     */
    public function add_email_actions( array $actions ): array {
        $actions[] = self::POINTS_EARNED_ACTION;
        $actions[] = self::POINTS_ADJUSTED_ACTION;

        return $actions;
    }

    /**
     * Passes an award to the Points earned email as its ledger row id: with deferred emails, WooCommerce
     * before 10.8 serializes an action's arguments, and the award's Context may hold a Closure.
     */
    public function relay_award( Outcome $awarded ): void {
        $entry = $awarded->get_entry();

        if ( ! $entry || null === $entry->get_id() ) {
            return;
        }

        /**
         * Fires once per award the Points earned email should report. WooCommerce relays it to
         * {action}_notification, at once or at the end of the request.
         *
         * @param int $entry_id The awarded ledger row.
         */
        do_action( self::POINTS_EARNED_ACTION, $entry->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name; the sniff cannot read it.
    }

    /**
     * Passes a manual adjustment to the Points adjusted email. Called by the adjust endpoint, not hooked,
     * because only that request knows whether its admin asked to notify.
     */
    public static function relay_adjustment( LedgerEntry $entry ): void {
        /**
         * Fires once per manual adjustment whose admin asked to notify the customer. WooCommerce relays it
         * to {action}_notification, at once or at the end of the request.
         *
         * @param int $entry_id The adjustment's ledger row.
         */
        do_action( self::POINTS_ADJUSTED_ACTION, $entry->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name; the sniff cannot read it.
    }

    /**
     * Queues the Points expired email for an EXPIRE row, paced by QueuedEmails. Called by the expiry job
     * for each row it wrote.
     */
    public static function relay_expiry( LedgerEntry $entry ): void {
        QueuedEmails::enqueue( self::POINTS_EXPIRED_EMAIL_ID, [ (int) $entry->get_id() ] );
    }

    /**
     * Queues the Points expiring soon email for a claimed reminder, paced by QueuedEmails. Called by the
     * expiry job for each reminder it claimed. The figures travel as scalars: the claim already marked
     * the lots, so a later send could not recompute them.
     */
    public static function relay_expiring_soon( int $user_id, int $points, string $expires_at, bool $is_single_date ): void {
        QueuedEmails::enqueue( self::POINTS_EXPIRING_SOON_EMAIL_ID, [ $user_id, $points, $expires_at, $is_single_date ] );
    }

    /**
     * Gives WooCommerce's settings preview loyalty sample data. Otherwise it renders a loyalty email
     * around a dummy order, with {points} blank.
     *
     * @param \WC_Email $email
     * @return \WC_Email
     */
    public function prepare_preview( $email ) {
        if ( $email instanceof LoyaltyEmail ) {
            $email->prepare_preview();
        }

        return $email;
    }

    /**
     * @param array<string, \WC_Email> $classes
     * @return array<string, \WC_Email>
     */
    public function add_classes( array $classes ): array {
        $classes = self::add( $classes, new PointsEarnedEmail(), new PointsAdjustedEmail() );

        return self::is_expiry_on() ? self::add( $classes, new PointsExpiringSoonEmail(), new PointsExpiredEmail() ) : $classes;
    }

    /** Whether points expiry is on. While it is off nothing sends the expiry emails, so they are not registered. */
    public static function is_expiry_on(): bool {
        return ExpiryPolicy::current()->is_enabled();
    }

    /**
     * Adds loyalty emails to WooCommerce's list, keyed by id: WooCommerce opens an
     * email's settings screen at section=<key>, so the id is also that section.
     *
     * @param array<string, \WC_Email> $classes
     * @return array<string, \WC_Email>
     */
    public static function add( array $classes, LoyaltyEmail ...$emails ): array {
        foreach ( $emails as $email ) {
            $classes[ $email->id ] = $email;
        }

        return $classes;
    }

    /**
     * @param array<string, string> $groups
     * @return array<string, string>
     */
    public function add_groups( array $groups ): array {
        $groups[ self::GROUP_POINTS ] = __( 'Loyalty: Points', 'yayloyalty' );

        return $groups;
    }
}
