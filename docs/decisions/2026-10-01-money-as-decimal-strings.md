# Store money as DECIMAL and carry it as a string

**Purpose:** why stored money is `DECIMAL` and travels as a string through PHP, REST and the SPA, never as a float.

**Update when:** never edit it. Supersede it with a new record if stored money gets another representation.

## Status

Accepted, 2026-10-01, by Trace Bui, who confirmed the reasons. Backfilled: the decision was made on 2026-09-09, in `cab8497`.

## Context

- Stored money feeds decisions a merchant pays for: today, the lifetime-spend condition of Pro campaigns; once redemption is built, discount values and redemption rates.
- A binary float can't represent most decimal fractions exactly. `$wpdb`'s `%f` placeholder turns a value into a float, and so does `parseFloat` in the SPA.
- So a float "loses precision exactly where it is a financial defect" (`includes/Models/AbstractModel.php`).

## Decision

- Money columns are `DECIMAL(19,4)`.
- PHP carries a stored money value as a string (`AbstractModel::cast_decimal()`) and binds it with `%s`, never `%f`.
- REST sends it as a string, and the SPA keeps it a string (`z.string()`) rather than parsing it into a number.

## Consequences

- A stored value keeps its exact digits from the database to the screen and back.
- Neither PHP nor JavaScript has a built-in decimal type, so arithmetic on money is deliberate work: convert in one place, and round once, to the currency's decimals.
- A float that slips in, through `%f`, `parseFloat` or a float-typed property, is a defect that no lint rule catches. Review has to.
- The working rules, rounding included, are in [conventions.md](../conventions.md#money).
