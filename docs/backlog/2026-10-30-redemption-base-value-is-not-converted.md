# A redemption's base_value holds the charged-currency amount, not the base-currency one

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** Misleading-names review, 2026-10-30, branch trace/refactor/misleading-names (left out of the rename because it changes behaviour).

## Today

Both builders of a `yayloyalty_redemptions` row in `Engine\Redemption\CommitWriter` pass the same string as `value` and as `base_value`: `redemption_from()` (the flexible discount, whose docblock says "`base_value` equals `value`: there is no currency conversion") and `reward_row()` (a reward line). `value` is in the order's currency. `Models\Redemption::$base_value` is documented as "the same value in the store's base currency, for reports". A conversion now exists: `Utils\CurrencyRate::to_base()` takes the order, so it uses the rate `Integrations\YayCurrency\OrderRateSnapshot` saved on it. Nothing reads `Redemption::get_base_value()` yet.

## Why it matters

On a store running YayCurrency, an order paid in another currency writes, say, 250,000 ₫ into `base_value` as if it were 250,000 of the base currency. The first report that sums `base_value`, as the model invites, overstates or understates redemptions by the exchange rate, and the rows already written can't be corrected afterwards because the rate isn't on the row.

## Suggested fix

In both builders, set `base_value` to `CurrencyRate::to_base( $value, $currency, $order )`, rounded once to the base currency's decimals, and fix the `redemption_from()` docblock. If no report will ever need it, drop the column and the model field instead; dbDelta never drops a column, so that also needs a step in `Database\Installer::run_migrations()`. It was left out of the rename because either way changes what is stored.

## Done when

- [ ] `CommitWriter::redemption_from()` sets `base_value` from `CurrencyRate::to_base()` with the order, or `base_value` is no longer a column of `RedemptionsTable::definition()`.
- [ ] `CommitWriter::reward_row()` sets `base_value` from `CurrencyRate::to_base()` with the order, or `base_value` is no longer a column of `RedemptionsTable::definition()`.
- [ ] The `CommitWriter::redemption_from()` docblock no longer says there is no currency conversion.
- [ ] A redemption on a non-base-currency order (YayCurrency active) writes a `base_value` equal to `value` divided by the order's `_yayloyalty_currency_rate` meta.
