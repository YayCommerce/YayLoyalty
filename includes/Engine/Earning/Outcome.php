<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Engine\Points\Result as EarnResult;
use YayLoyalty\Engine\Rewards\Result as RedeemResult;
use YayLoyalty\Models\LedgerEntry;

defined( 'ABSPATH' ) || exit;

/**
 * What an award or a redemption commit did: wrote a row, skipped for a reason, or found the row
 * already there. Duplicate is a normal outcome, not an exception, so a re-fired hook stays silent.
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

    /**
     * $evaluation is null when the writer produced this: LedgerService::earn()
     * is handed a draft row and never sees the Result behind it, so
     * EarningService::award(), which has both, re-wraps before returning.
     */
    public static function awarded( LedgerEntry $entry, ?EarnResult $evaluation = null ): self {
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

    /** Produced by the writer (LedgerService::earn() or redeem()), which never sees the evaluation, so none is carried. */
    public static function duplicate( LedgerEntry $existing ): self {
        return new self( self::DUPLICATE, $existing, null, '' );
    }

    /**
     * Points an earning limit held back: the evaluated points minus those
     * written, all of them for a skip. 0 for a duplicate, a redemption, or
     * a skip before the engine ran or that the engine itself answered.
     */
    public function held_back(): int {
        if ( ! $this->evaluation instanceof EarnResult ) {
            return 0;
        }

        $written = null === $this->entry ? 0 : $this->entry->get_points();

        return max( 0, $this->evaluation->get_points() - $written );
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
