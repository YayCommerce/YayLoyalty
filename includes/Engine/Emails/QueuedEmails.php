<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Engine\Expiry\ExpiryRunner;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Paced delivery for the expiry job's emails. Each email becomes one Action Scheduler action in
 * ExpiryRunner::GROUP, at the next pacing slot (one every interval() seconds), so a large expiry day
 * reaches the mail server at a rate it can absorb. The handler sends through the email's own trigger()
 * and reads its send result: a transport failure is rescheduled after BACKOFFS, everything else is
 * dropped. It calls trigger() directly rather than through a WooCommerce email action, because that
 * relay discards the result and may defer the send again.
 * Without Action Scheduler, an email sends inline.
 */
final class QueuedEmails {

    /**
     * Internal Action Scheduler hook that sends one queued email. Not a public API.
     *
     * @param string $email   The email's id, as WooCommerce's mailer keys it.
     * @param array  $args    The email's trigger() arguments, scalars only.
     * @param int    $attempt 1 for the first send, then one more per retry.
     */
    public const HOOK = 'yayloyalty_send_queued_email';

    /** The earliest time the next queued email may go out, Unix seconds. Not autoloaded. */
    public const OPTION_NEXT_SLOT = 'yayloyalty_email_queue_next_slot';

    /**
     * Filters the seconds between two queued emails. A value below 1 counts as 1.
     *
     * @param int $seconds 15 by default.
     */
    public const INTERVAL_FILTER = 'yayloyalty_queued_email_interval';

    /** Seconds to wait after failed attempt N. The last attempt has no entry, so it is not retried. */
    public const BACKOFFS = [
        1 => 300,
        2 => 1800,
        3 => 7200,
    ];

    public function register(): void {
        add_action( self::HOOK, [ $this, 'handle' ], 10, 3 );
    }

    /**
     * Queues one send of $email_id at the next pacing slot.
     *
     * @param array<int, scalar> $args The email's trigger() arguments.
     */
    public static function enqueue( string $email_id, array $args ): void {
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            ( new self() )->handle( $email_id, $args, 1 );
            return;
        }

        self::schedule( $email_id, $args, 1, time() );
    }

    /**
     * Sends one queued email and reschedules it only when the transport failed.
     *
     * @param array<int, scalar> $args The email's trigger() arguments.
     */
    public function handle( string $email_id, array $args, int $attempt ): void {
        $email = WC()->mailer()->get_emails()[ $email_id ] ?? null;

        // Not registered (expiry turned off since it was queued): skipped.
        if ( ! $email instanceof LoyaltyEmail ) {
            return;
        }

        try {
            $result = $email->trigger( ...$args );
        } catch ( \Throwable $e ) {
            Log::warning( 'QueuedEmails', sprintf( '%s attempt %d threw: %s', $email_id, $attempt, $e->getMessage() ) );
            $result = LoyaltyEmail::SEND_FAILED;
        }

        if ( LoyaltyEmail::SEND_FAILED !== $result ) {
            return;
        }

        if ( isset( self::BACKOFFS[ $attempt ] ) && function_exists( 'as_schedule_single_action' ) ) {
            self::schedule( $email_id, $args, $attempt + 1, time() + self::BACKOFFS[ $attempt ] );
            return;
        }

        Log::error( 'QueuedEmails', sprintf( '%s %s failed after %d attempts; dropped', $email_id, wp_json_encode( $args ), $attempt ) );
    }

    /** @param array<int, scalar> $args */
    private static function schedule( string $email_id, array $args, int $attempt, int $earliest ): void {
        as_schedule_single_action(
            self::take_slot( $earliest ),
            self::HOOK,
            [
                'email'   => $email_id,
                'args'    => $args,
                'attempt' => $attempt,
            ],
            ExpiryRunner::GROUP
        );
    }

    /** The first free slot at or after $earliest, which it reserves. Two racing callers may share one. */
    private static function take_slot( int $earliest ): int {
        $slot = max( $earliest, (int) get_option( self::OPTION_NEXT_SLOT, 0 ) );

        update_option( self::OPTION_NEXT_SLOT, $slot + self::interval(), false );

        return $slot;
    }

    private static function interval(): int {
        return max( 1, (int) apply_filters( self::INTERVAL_FILTER, 15 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name; the sniff cannot read it.
    }
}
