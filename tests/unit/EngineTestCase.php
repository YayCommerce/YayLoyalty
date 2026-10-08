<?php
namespace YayLoyalty\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\ContextLineItem;
use YayLoyalty\Engine\Points\CustomerFacts;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;

/**
 * Fixture builders shared by the engine tests. The default purchase campaign earns 1 point per
 * currency unit after discounts, rounds down, and has no exclusions or gates. The base currency
 * is the order currency.
 */
abstract class EngineTestCase extends TestCase {

    protected const AT = '2026-06-15 12:00:00';

    protected function tearDown(): void {
        $GLOBALS['yayloyalty_test_hooks'] = [];
    }

    /**
     * @param array<string, mixed> $config Merged over the purchase defaults.
     */
    protected function purchase_campaign(
        array $config = [],
        int $priority = 0,
        ?int $id = 1,
        string $status = Status::ACTIVE,
        ?string $starts_at = null,
        ?string $ends_at = null,
        string $slug = 'campaign'
    ): Campaign {
        return new Campaign(
            $id,
            'Campaign ' . $id,
            $slug,
            CampaignTrigger::PURCHASE,
            $status,
            $priority,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), $config ),
            [],
            $starts_at,
            $ends_at,
            CampaignRepository::DEFAULT_PURCHASE_SLUG === $slug
        );
    }

    protected function default_purchase_campaign( array $config = [], string $status = Status::ACTIVE ): Campaign {
        return $this->purchase_campaign( $config, 0, 1, $status, null, null, CampaignRepository::DEFAULT_PURCHASE_SLUG );
    }

    protected function flat_campaign( string $trigger, array $config, ?int $id = 1 ): Campaign {
        return new Campaign( $id, 'Flat ' . $id, 'flat-' . $id, $trigger, Status::ACTIVE, 0, $config );
    }

    /**
     * @param int[] $category_ids
     */
    protected function line( float $subtotal, ?float $total = null, int $product_id = 10, array $category_ids = [], float $tax = 0.0 ): ContextLineItem {
        return new ContextLineItem( $product_id, null, $category_ids, 1, $subtotal, $total ?? $subtotal, $tax );
    }

    /**
     * @param ContextLineItem[]    $lines
     * @param string[]             $coupons
     * @param array<string, mixed> $extra
     */
    protected function context(
        array $lines,
        float $loyalty_discount = 0.0,
        array $coupons = [],
        float $base_rate = 1.0,
        ?int $user_id = 7,
        array $extra = [],
        ?int $comment_id = null,
        ?CustomerFacts $facts = null
    ): Context {
        $subtotal = 0.0;
        $total    = 0.0;

        foreach ( $lines as $line ) {
            $subtotal += $line->get_subtotal();
            $total    += $line->get_total();
        }

        return new Context(
            $user_id,
            self::AT,
            'USD',
            $base_rate,
            $lines,
            $subtotal,
            $subtotal - $total,
            $loyalty_discount,
            $coupons,
            [ 'customer' ],
            555,
            $comment_id,
            $extra,
            $facts
        );
    }

    /**
     * A repository that answers from memory. Nothing here touches $wpdb.
     *
     * @param Campaign[] $campaigns
     */
    protected function repository( array $campaigns ): CampaignRepository {
        return new class( $campaigns ) extends CampaignRepository {
            /** @var Campaign[] */
            private $rows;

            public function __construct( array $rows ) {
                $this->rows = $rows;
            }

            public function all_non_archived(): array {
                $rows = array_filter( $this->rows, static fn( Campaign $c ) => ! $c->is_archived() );

                usort(
                    $rows,
                    static function ( Campaign $a, Campaign $b ): int {
                        return [ $b->get_priority(), $b->get_id() ] <=> [ $a->get_priority(), $a->get_id() ];
                    }
                );

                return array_values( $rows );
            }

            public function flush_cache(): void {}
        };
    }
}
