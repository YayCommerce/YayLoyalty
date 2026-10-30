# A redemption's rate_snapshot rounds a converted rate to four decimals, often to 0

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** Misleading-names review, 2026-10-30, branch trace/refactor/misleading-names (left out of the rename because it changes behaviour).

## Today

`Engine\Rewards\Clamp::apply()` returns `Clamp::effective_rate()` as the quote's rate snapshot: the reward's `points_per_currency_unit` divided by the currency rate, so points per unit of the charged currency. `Engine\Redemption\CommitWriter::redemption_from()` writes it as `(string) $quote->get_rate_snapshot()` into `rate_snapshot`, which `Database\Schema\RedemptionsTable::definition()` declares `decimal(19,4)`. At 1 point per US dollar and 25,000 ₫ per dollar the rate is 0.00004, which PHP casts to `"4.0E-5"` and the column stores as 0.0000. A larger rate such as 100 / 23,000 keeps only four decimals (0.0043, about 1% off). `CommitWriter::reward_row()` writes `'0'` for reward lines. Nothing reads the column: `Engine\Redemption\Reversal` reverses from `points` and `restored_points`, although the `Engine\Rewards\Result` docblock says the snapshot lets a refund reverse at the quoted rate.

## Why it matters

The row is meant to keep the rate as it was at redemption time. On a store whose shoppers pay in a weak currency, every flexible-discount row records a rate of 0 or a rounded one, so a later refund rule or report that trusts it computes the wrong points.

## Suggested fix

Widen `rate_snapshot` to as many decimals as `OrderRateSnapshot::stage()` keeps (10), and write it with `wc_format_decimal( $rate, 10 )` instead of a string cast; dbDelta applies a changed column type. Correct the `Result` docblock to say what reads the snapshot, or drop the column with `base_value` (see the base_value item) if nothing will. Left out of the rename because it changes the schema and what is stored.

## Done when

- [ ] `RedemptionsTable::definition()` declares `rate_snapshot` with at least 10 decimals, or no longer declares it.
- [ ] `CommitWriter::redemption_from()` formats the rate with `wc_format_decimal()`, not a `(string)` cast.
- [ ] The `Engine\Rewards\Result` class docblock names the code that reads `rate_snapshot`, or no longer says a refund reverses at that rate.
- [ ] A flexible-discount redemption at a points rate of 0.00004 per unit of the charged currency stores a nonzero `rate_snapshot`.
