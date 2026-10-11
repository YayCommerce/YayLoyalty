<?php
namespace YayLoyalty\Tests\Integration\Emails;

use YayLoyalty\Engine\Emails\PointsAdjustedEmail;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** The Points adjusted email's template values for a manual adjustment's customer note. */
final class PointsAdjustedEmailTest extends IntegrationTestCase {

    public function test_values_include_customer_note(): void {
        $entry = $this->ledger_service()->adjust( 424242, 50, 'internal reason', 1, true, 'Thanks for the review!' );

        $this->assertSame( 'Thanks for the review!', $this->values( $entry )['customer_note'] );
    }

    public function test_values_have_empty_customer_note_without_one(): void {
        $entry = $this->ledger_service()->adjust( 424242, 50, 'internal reason', 1 );

        $this->assertSame( '', $this->values( $entry )['customer_note'] );
    }

    /** @return array<string, mixed> */
    private function values( LedgerEntry $entry ): array {
        $method = new \ReflectionMethod( PointsAdjustedEmail::class, 'build_values' );
        $method->setAccessible( true );

        return $method->invoke( new PointsAdjustedEmail(), $entry );
    }
}
