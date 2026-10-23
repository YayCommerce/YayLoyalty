<?php
namespace YayLoyalty\Engine\Redemption;

defined( 'ABSPATH' ) || exit;

/**
 * The only mutable state on the quote path, in the WooCommerce session: the points the customer asked to
 * spend on the slider and the reward ids they picked, each set on its own, plus the names of picked rewards the store
 * took away, for the card's notice. Never a discount value — that is derived
 * from the current cart on every recalculation — and never a Quote. Every method returns quietly when
 * WooCommerce has no session.
 */
final class SessionIntent {

    /** WC session key; the integer lives nowhere else until the order exists. */
    public const KEY = 'yayloyalty_redeem_points';

    /** WC session key of the picked reward ids, unique, in the order they were picked. */
    public const REWARDS_KEY = 'yayloyalty_redeem_rewards';

    /**
     * WC session key of the names of picked rewards the store took away (paused, archived, ended or deleted),
     * which the card reports once, at its next render; '' stands for a deleted reward, whose name is gone.
     */
    public const DROPPED_KEY = 'yayloyalty_redeem_dropped';

    /** Postcondition: the stored integer, 0 when nothing was asked or there is no session. Never negative. */
    public function get(): int {
        $session = $this->session();

        return null === $session ? 0 : max( 0, (int) $session->get( self::KEY, 0 ) );
    }

    /**
     * Precondition: $points ≥ 0, unclamped — the clamp runs on read, not on
     * write, so a later cart change can never leave a stale ceiling here.
     * Postcondition: get() returns $points; 0 removes the key. The rewards are untouched.
     */
    public function set( int $points ): void {
        $session = $this->session();

        if ( null === $session ) {
            return;
        }

        if ( $points <= 0 ) {
            $session->__unset( self::KEY );

            return;
        }

        $session->set( self::KEY, $points );
    }

    /**
     * The picked reward ids, positive and unique, in the order they were picked. [] when none or no session.
     *
     * @return int[]
     */
    public function rewards(): array {
        $session = $this->session();

        return null === $session ? [] : self::normalise( (array) $session->get( self::REWARDS_KEY, [] ) );
    }

    /**
     * Postcondition: rewards() returns the positive ids of $reward_ids, unique, in their order; [] removes the
     * key. The points are untouched.
     *
     * @param array $reward_ids
     */
    public function set_rewards( array $reward_ids ): void {
        $session = $this->session();

        if ( null === $session ) {
            return;
        }

        $reward_ids = self::normalise( $reward_ids );

        if ( [] === $reward_ids ) {
            $session->__unset( self::REWARDS_KEY );

            return;
        }

        $session->set( self::REWARDS_KEY, $reward_ids );
    }

    /** Postcondition: rewards() no longer holds $reward_id; the rest keep their order. */
    public function remove_reward( int $reward_id ): void {
        $this->set_rewards( array_diff( $this->rewards(), [ $reward_id ] ) );
    }

    /**
     * The card's request, from either endpoint: set_rewards( $reward_ids ) when given, then set( $points ) when
     * given, each leaving the other key alone; set( 0 ) when neither is. Unclamped, as both setters are.
     *
     * @param int[]|null $reward_ids
     */
    public function apply( ?array $reward_ids, ?int $points ): void {
        if ( null !== $reward_ids ) {
            $this->set_rewards( $reward_ids );
        }

        if ( null !== $points || null === $reward_ids ) {
            $this->set( (int) $points );
        }
    }

    /**
     * The names of the picked rewards the store took away, in the order they were dropped; '' for a deleted one.
     *
     * @return string[]
     */
    public function dropped(): array {
        $session = $this->session();

        return null === $session ? [] : array_values( array_filter( (array) $session->get( self::DROPPED_KEY, [] ), 'is_string' ) );
    }

    /** Postcondition: dropped() holds $name once, after the names already there. */
    public function add_dropped( string $name ): void {
        $session = $this->session();
        $dropped = $this->dropped();

        if ( null === $session || in_array( $name, $dropped, true ) ) {
            return;
        }

        $dropped[] = $name;
        $session->set( self::DROPPED_KEY, $dropped );
    }

    /** Postcondition: dropped() returns []. Called once the card has shown them. */
    public function clear_dropped(): void {
        $session = $this->session();

        if ( null !== $session ) {
            $session->__unset( self::DROPPED_KEY );
        }
    }

    /** Postcondition: get() returns 0, rewards() [] and dropped() []. Called by the commit. */
    public function clear(): void {
        $session = $this->session();

        if ( null !== $session ) {
            $session->__unset( self::KEY );
            $session->__unset( self::REWARDS_KEY );
            $session->__unset( self::DROPPED_KEY );
        }
    }

    /**
     * @param array $ids
     * @return int[]
     */
    private static function normalise( array $ids ): array {
        $ids = array_filter(
            array_map( 'intval', $ids ),
            static function ( int $id ): bool {
                return $id > 0;
            }
        );

        return array_values( array_unique( $ids ) );
    }

    private function session(): ?\WC_Session {
        if ( ! function_exists( 'WC' ) ) {
            return null;
        }

        $session = WC()->session;

        return $session instanceof \WC_Session ? $session : null;
    }
}
