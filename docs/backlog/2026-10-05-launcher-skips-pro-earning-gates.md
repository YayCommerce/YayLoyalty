# The launcher shows the earning panel past gates that Pro may add

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-05 `/simplify`, `/yaytidy` and `/code-review` of the storefront launcher, before it merged into `dev/edd` (#41).

## Today

`Launcher::should_render()` builds `ProgramGate` itself. A guest gets `ProgramGate::program_enabled()`, and a member gets `ProgramGate::check()`. Earning runs a gate chain instead: `EarningService::gates()` is `ProgramGate` plus whatever the `yayloyalty_earning_gates` filter adds, and `EarningService::blocked_reason()` runs it.

## Why it matters

Pro adds no gate today. Once it does, a shopper that gate blocks from earning will still see a panel about earning.

A related gap, the Pro daily cap, is its own item: [the launcher advertises earning to a shopper at the daily cap](2026-10-08-launcher-advertises-earning-past-the-daily-cap.md).

## Suggested fix

Expose the filtered chain, for example by making `blocked_reason()` public or adding a static that runs the gates. The launcher then calls it and reads `ProgramGate::REASON_NO_CUSTOMER` as "a guest: show the pitch", and `program_enabled()` can go.

## Done when

- [ ] `Launcher::should_render()` runs the gate chain that the `yayloyalty_earning_gates` filter builds, not `ProgramGate` alone.
- [ ] `ProgramGate::program_enabled()` is gone, and `git grep -n program_enabled -- includes` finds nothing.
