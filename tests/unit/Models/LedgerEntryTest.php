<?php
namespace YayLoyalty\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\LedgerEntry;

/** `to_array()` exposes `note` on manual-adjustment rows only; system rows never carry one over the wire. */
final class LedgerEntryTest extends TestCase {

    private function entry( string $type, ?string $note ): LedgerEntry {
        return new LedgerEntry( 1, 42, 100, 500, $type, LedgerSource::MANUAL, null, null, null, null, null, $note, 9, '2026-09-24 10:00:00' );
    }

    public function test_to_array_exposes_note_for_adjust(): void {
        $this->assertSame( 'goodwill', $this->entry( LedgerType::ADJUST, 'goodwill' )->to_array()['note'] );
    }

    public function test_to_array_exposes_note_for_correction(): void {
        $this->assertSame( 'balance only', $this->entry( LedgerType::CORRECTION, 'balance only' )->to_array()['note'] );
    }

    public function test_to_array_nulls_note_for_system_rows(): void {
        $this->assertNull( $this->entry( LedgerType::EARN, 'should never surface' )->to_array()['note'] );
    }
}
