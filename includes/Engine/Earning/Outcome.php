<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Engine\Points\Result as EarnResult;
use YayLoyalty\Engine\Rewards\Result as RedeemResult;
use YayLoyalty\Models\LedgerEntry;

defined( 'ABSPATH' ) || exit;

/**
 * What a write moment did — earning's award or redemption's commit: wrote
 * a row, wrote nothing for a reason, or found the row already there.
 * Duplicate is a normal outcome, never an exception, so a re-fired hook is
 * silent by design. One shape for both sides so listeners, order notes and
 * tests read one vocabulary.
 */
final class Outcome {

    public const AWARDED   = 'awarded';
    public const REDEEMED  = 'redeemed';
    public const SKIPPED   = 'skipped';
    public const DUPLICATE = 'duplicate';

    private string $status;

    private ?LedgerEntry $entry;

    /** @var EarnResult|RedeemResult|null Typed as object because PHP 7.4 has no union types; awarded() carries an EarnResult, redeemed() a RedeemResult. */
    private ?object $evaluation;

    /** A gate reason, a Result::REASON_* constant, or '' when awarded/redeemed/duplicate. */
    private string $reason;

    private function __construct( string $status, ?LedgerEntry $entry, ?object $evaluation, string $reason ) {
        $this->status     = $status;
        $this->entry      = $entry;
        $this->evaluation = $evaluation;
        $this->reason     = $reason;
    }

    public static function awarded( LedgerEntry $entry, EarnResult $evaluation ): self {
        return new self( self::AWARDED, $entry, $evaluation, '' );
    }

    /** The redemption commit wrote its REDEEM row; $entry is that row, $evaluation the quote it was written from. */
    public static function redeemed( LedgerEntry $entry, RedeemResult $evaluation ): self {
        return new self( self::REDEEMED, $entry, $evaluation, '' );
    }

    /**
     * $evaluation is null when a gate stopped the write before the engine ran.
     *
     * @param EarnResult|RedeemResult|null $evaluation
     */
    public static function skipped( string $reason, ?object $evaluation = null ): self {
        return new self( self::SKIPPED, null, $evaluation, $reason );
    }

    /** Produced by the writer (LedgerService::earn() / redeem()), which never sees the evaluation — so none is carried. */
    public static function duplicate( LedgerEntry $existing ): self {
        return new self( self::DUPLICATE, $existing, null, '' );
    }

    public function get_status(): string {
        return $this->status;
    }

    /** The persisted row for awarded, the pre-existing row for duplicate, null for skipped. */
    public function get_entry(): ?LedgerEntry {
        return $this->entry;
    }

    /**
     * @return EarnResult|RedeemResult|null
     */
    public function get_evaluation(): ?object {
        return $this->evaluation;
    }

    public function get_reason(): string {
        return $this->reason;
    }
}
