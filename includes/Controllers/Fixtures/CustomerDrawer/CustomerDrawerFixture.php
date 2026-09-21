<?php
namespace YayLoyalty\Controllers\Fixtures\CustomerDrawer;

defined( 'ABSPATH' ) || exit;

/**
 * Dev-only customer drawer artboards (F09 Wave A).
 *
 * SWAP POINT — `GET /customers/{id}` and `GET /customers/{id}/ledger` answer
 * from here instead of the real repositories when `?scenario=` is present,
 * `WP_DEBUG` only: a dev store's handful of seeded customers isn't enough to
 * build or review the drawer's states against (a brand-new account, a
 * 250k-member top-tier account, a deleted account, an expiring balance, a
 * negative balance). Every scenario emits the exact wire shape phase 01
 * defined, parsed with the same `customerSchema`/`ledgerEnvelopeSchema` as
 * the real path, so a drift here fails loudly on the dev server. Nothing
 * else reads this class; when the artboards are signed off it is deleted
 * together with the `scenario` arg and nothing else in the SPA changes.
 *
 * Gated the way `DashboardRestController` gates its own `scenario` arg —
 * NOT the way `CampaignRestController::dummy_fixture()` serves unconditionally.
 * The Customers list is real data; an unconditional drawer fixture would
 * make the list and the drawer disagree on screen during review.
 */
final class CustomerDrawerFixture {

    /** No `''` entry — absence of the param means the real repository path. */
    public const SCENARIOS = [ 'new', 'large', 'deleted', 'expiring', 'negative' ];

    /**
     * The `scenario` REST arg descriptor every drawer controller merges into
     * its route args, so the enum is defined once. Registered only under
     * `WP_DEBUG`, which is what gives dev requests enum validation. WP REST
     * still exposes an unregistered `?scenario=` via `$request['scenario']`,
     * so `requested()` re-checks `enabled()` — that check, not registration,
     * is the production guard.
     *
     * @return array<string, mixed>
     */
    public static function scenario_arg(): array {
        if ( ! self::enabled() ) {
            return [];
        }

        return [
            'scenario' => [
                'description'       => __( 'Dev-only drawer artboard to render instead of real data.', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => self::SCENARIOS,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_key',
            ],
        ];
    }

    /** The scenario a request asks for, or null for the real repository path — the one gate every handler reads. */
    public static function requested( \WP_REST_Request $request ): ?string {
        if ( ! self::enabled() || empty( $request['scenario'] ) ) {
            return null;
        }

        return (string) $request['scenario'];
    }

    private static function enabled(): bool {
        return defined( 'WP_DEBUG' ) && WP_DEBUG;
    }

    public static function customer( string $scenario, int $user_id ): array {
        return DrawerProfiles::to_wire( $scenario, $user_id );
    }

    /**
     * @param array{page?:int,per_page?:int,type?:string,search?:string,after?:string,before?:string} $args
     * @return array{items: array, total: int, page: int, per_page: int, type_counts: array}
     */
    public static function ledger( string $scenario, int $user_id, array $args ): array {
        return DrawerLedger::envelope( $scenario, $user_id, $args );
    }

    /**
     * @param array{page:int,per_page:int,search?:string,after?:string,before?:string} $args
     * @return array{items: array, total: int, page: int, per_page: int}
     */
    public static function activity( string $scenario, string $group, array $args ): array {
        return DrawerActivity::envelope( $scenario, $group, $args );
    }

    /**
     * @param array{page:int,per_page:int} $args
     * @return array{items: array, total: int, page: int, per_page: int}
     */
    public static function redemptions( string $scenario, array $args ): array {
        return DrawerRedemptions::envelope( $scenario, $args );
    }

    /**
     * @param array{page:int,per_page:int} $args
     * @return array{items: array, total: int, page: int, per_page: int, referral_link: string, campaign: array}
     */
    public static function referrals( string $scenario, array $args ): array {
        return DrawerReferrals::envelope( $scenario, $args );
    }
}
