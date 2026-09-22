<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Engine\Points\Result;
use YayLoyalty\Models\LedgerEntry;

defined( 'ABSPATH' ) || exit;

/**
 * What the award moment did: wrote a row, wrote nothing for a reason, or
 * found the row already there. Duplicate is a normal outcome, never an
 * exception, so a re-fired hook is silent by design.
 */
final class Outcome {

    public const AWARDED   = 'awarded';
    public const SKIPPED   = 'skipped';
    public const DUPLICATE = 'duplicate';

    private string $status;

    private ?LedgerEntry $entry;

    private ?Result $evaluation;

    /** A gate reason, a Result::REASON_* constant, or '' when awarded/duplicate. */
    private string $reason;

    private function __construct( string $status, ?LedgerEntry $entry, ?Result $evaluation, string $reason ) {
        $this->status     = $status;
        $this->entry      = $entry;
        $this->evaluation = $evaluation;
        $this->reason     = $reason;
    }

    public static function awarded( LedgerEntry $entry, Result $evaluation ): self {
        return new self( self::AWARDED, $entry, $evaluation, '' );
    }

    /** $evaluation is null when a gate stopped the award before the engine ran. */
    public static function skipped( string $reason, ?Result $evaluation = null ): self {
        return new self( self::SKIPPED, null, $evaluation, $reason );
    }

    public static function duplicate( LedgerEntry $existing ): self {
        return new self( self::DUPLICATE, $existing, null, '' );
    }

    public function get_status(): string {
        return $this->status;
    }

    public function is_awarded(): bool {
        return self::AWARDED === $this->status;
    }

    public function is_duplicate(): bool {
        return self::DUPLICATE === $this->status;
    }

    /** The persisted row for awarded, the pre-existing row for duplicate, null for skipped. */
    public function get_entry(): ?LedgerEntry {
        return $this->entry;
    }

    public function get_evaluation(): ?Result {
        return $this->evaluation;
    }

    public function get_reason(): string {
        return $this->reason;
    }
}
