---
id: UX-1
title: Condition groups editor
status: ready
from: BS-1
surfaces: [admin]
editions: [pro]
mockup:
created: 2026-11-13
---
# UX-1 Condition groups editor

> **Superseded in part (2026-11-13):** groups were dropped (P-1 Revision). The editor is the "one group" screen below for good: "Match [all|any] of these conditions" above the rows, an "Add condition" menu, no "Add group" and no group cards. The reward editor's "Who can redeem" uses the same list.

## Job
A store admin targets a campaign or a reward at a mix of customers, such as "country is US AND (lifetime spend at least $500 OR order count at least 5)". They should build that in the editor they already use, without learning a query language. Done means: they save it, and when they reopen it the groups and the AND/OR choices are exactly as they left them.

## Where it lives
- **Campaign editor**, step 3 "Conditions" (`apps/admin/src/pro/campaigns/editor/CampaignEditorSections.tsx:23`). The existing section gains groups. Nothing moves.
- **Reward editor**, a new step 3 "Who can redeem", for catalog rewards only (fixed coupon, percent coupon, free shipping, free product). It sits after "Cost and value" (`apps/admin/src/pro/rewards/editor/RewardEditorSections.tsx:37-43`). The built-in "Points discount" (flexible) reward gets no section: its limits are config, and who may spend points at all is the program's excluded roles.
- **Campaigns table** "Conditions" column, the dashboard's top campaigns and campaign performance (`campaign-labels.ts conditionsLabel()`) show the grouped summary.
- **Rewards table** "Availability" column (`reward-labels.ts availabilityLabel()`) says "Selected customers" in place of "Everyone" when a reward has conditions.
- Both editors are Pro screens (`src/pro/`). Lite has no campaign or reward editor, so nothing new shows in Lite.

## Flow
1. The admin opens a campaign and goes to Conditions. With no conditions they see today's empty state and an "Add condition" button.
2. They add "Country". The rows look as they do today, with one new line above them: "Match [all] of these conditions".
3. They click "Add group". The section switches to group cards. Their country row is now in Group 1, a new empty Group 2 appears below it, and a "Customers must match [all] of these groups" select appears on top. The separator between the cards reads "AND".
4. In Group 2 they add "Lifetime spend" and "Order count" and set the group to "Match any of these conditions".
5. They save. On reload they see the same two groups, Group 1 "all", Group 2 "any", joined by AND. The campaigns table shows "Country AND (Lifetime spend OR Order count)".
- **Back to flat:** removing groups until one is left drops the group chrome again. One "all" group is stored the old way.
- **Error:** a row with an empty value shows its inline error, and the save is blocked with the existing toast "Please fix the highlighted conditions before saving."
- **Empty group:** a group with no conditions shows "Empty groups are removed when you save." It's dropped on save.

## Screens

### Conditions, one group (today's layout plus a match select)
```
 3  Conditions
 ┌──────────────────────────────────────────────────────────────────────┐
 │ Match [all ▾] of these conditions                                     │
 │ ┌──────────────────────────────────────────────────────────────────┐ │
 │ │ Lifetime spend  [at least ▾]  [ 500.00 | USD ]                [×] │ │
 │ └──────────────────────────────────────────────────────────────────┘ │
 │ ┌──────────────────────────────────────────────────────────────────┐ │
 │ │ Order count     [at least ▾]  [ 5 ]                           [×] │ │
 │ └──────────────────────────────────────────────────────────────────┘ │
 │ [+ Add condition ▾]  [+ Add group]                                    │
 └──────────────────────────────────────────────────────────────────────┘
```
- "Match … of these conditions" is a `Select` (`@/components/ui/select`, size sm, as the operator select in `CampaignEditorConditions.tsx:138-149`) with "all" and "any". It defaults to "all". It shows only once there is at least one condition.
- The condition rows reuse today's `ConditionRow` and value editors (`CampaignEditorConditions.tsx:111-407`). Only their field paths change.
- "Add condition" is today's dropdown (`DropdownMenu`). A type already used in this group is disabled, as today.
- "Add group" is a `Button variant="outline" size="sm"` beside it.

### Conditions, two or more groups
```
 3  Conditions
 ┌──────────────────────────────────────────────────────────────────────┐
 │ Customers must match [all ▾] of these groups                          │
 │ ┌─ Group 1 ──────────────────────── Match [all ▾] of these   [🗑] ─┐ │
 │ │ Country  [is any of ▾] [United States ×] [Billing address ▾]  [×] │ │
 │ │ [+ Add condition ▾]                                               │ │
 │ └───────────────────────────────────────────────────────────────────┘ │
 │                              ─── AND ───                              │
 │ ┌─ Group 2 ──────────────────────── Match [any ▾] of these   [🗑] ─┐ │
 │ │ Lifetime spend  [at least ▾]  [ 500.00 | USD ]                [×] │ │
 │ │ Order count     [at least ▾]  [ 5 ]                           [×] │ │
 │ │ [+ Add condition ▾]                                               │ │
 │ └───────────────────────────────────────────────────────────────────┘ │
 │ [+ Add group]                                       Up to 10 groups.  │
 └──────────────────────────────────────────────────────────────────────┘
```
- The top "Customers must match [all/any] of these groups" is a `Select`, default "all".
- Each group is a bordered card (`rounded-lg border p-3`, as a condition row today) rendered as a `<fieldset>` with legend "Group n". It holds its own "Match [all/any] of these" `Select`, a remove button (`Button variant="ghost" size="icon-sm"`, trash icon), its condition rows and its own "Add condition" dropdown.
- The separator between cards is muted text, "AND" or "OR", following the top select.
- "Add group" adds an empty group at the end and is disabled at 10 groups. The hint "Up to 10 groups." shows only then.
- Removing a group removes its conditions. When one group is left, the screen goes back to the one-group layout.

### Reward editor, "Who can redeem" (catalog rewards)
```
 3  Who can redeem
 ┌──────────────────────────────────────────────────────────────────────┐
 │  No conditions — every customer can redeem this reward                │
 │  Add one to limit it, for example to a customer role.                 │
 │ [+ Add condition ▾]  [+ Add group]                                    │
 └──────────────────────────────────────────────────────────────────────┘
```
- It's the same editor component with the reward vocabulary: Customer role, Specific customers, Lifetime points (`RewardConditionType::all()`). Everything else matches the campaign screens above.
- `EditorSection` (`@/components/ui/custom/editor-section`) step 3. It's absent for the "Points discount" reward.

## States
- **Empty:** campaign, today's `Empty` block, "No conditions — this applies to every order in the date range". Reward, "No conditions — every customer can redeem this reward".
- **Loading:** the editors' existing skeletons (`CampaignEditorSkeleton.tsx`, `RewardEditorSkeleton.tsx`). There's no new loading state.
- **Saving:** the editors' existing save buttons and pending state (`useEditorSave`).
- **Success:** the existing save toast. On reload the groups, their order and every all/any choice are as saved.
- **Validation error:** each bad row shows its inline `FieldError` as today. The save is blocked with "Please fix the highlighted conditions before saving." A group with no conditions isn't an error. It shows the hint and is dropped on save.
- **Failure:** a server 400 shows the existing error toast with "The campaign conditions are malformed." (or "The reward conditions are malformed."). The form keeps the merchant's edits.
- **No permission:** n/a. The editors need `manage_campaigns` / `manage_rewards` to open at all (unchanged).
- **Pro feature in Lite:** n/a. Both editors are Pro-only screens, and Lite shows no campaign or reward editor.

## Copy
- "Match" / "all" / "any" / "of these conditions" (one group): rendered as "Match [all] of these conditions".
- "Customers must match" / "of these groups" (two or more groups): "Customers must match [all] of these groups".
- "of these" (group header, after its select): "Match [any] of these".
- "Group %d" (legend; translators: %d is the group's position).
- "AND", "OR" (separators).
- "Add group".
- "Remove group %d" (aria-label).
- "Up to 10 groups."
- "Empty groups are removed when you save."
- "Who can redeem" (reward section title).
- "No conditions — every customer can redeem this reward".
- "Add one to limit it, for example to a customer role."
- "Selected customers" (rewards table Availability, in place of "Everyone").
- Campaigns table summary: type labels joined by " AND " / " OR ", with a multi-condition group in parentheses when there are two or more groups. For example, "Country AND (Lifetime spend OR Order count)".
- Unchanged and reused: "Add condition", "Remove condition", "No conditions", "Please fix the highlighted conditions before saving.". "Conditions are combined with AND." is removed, because the match select replaces it.

## Accessibility
- Every `Select` has an accessible name: "Match conditions" (one group), "Match groups" (top), "Group %d match" (per group).
- Each group is a `fieldset` with a visible `legend` "Group %d", so screen readers announce which group a row is in.
- Keyboard order: the top select, then each group (match select, remove button, rows, "Add condition"), then "Add group".
- After "Add group", focus moves to the new group's "Add condition" button. After removing a group, focus moves to "Add group".
- The AND/OR separator is text, not color, and it repeats the top select's choice. It's `aria-hidden`, because the selects already say it.
- RTL: the layout uses flex with logical gaps only. There are no left/right-specific margins.

## Decisions
- **Two levels** (groups of conditions), not a nested tree (BS-1 option A). The acceptance example needs one level of grouping.
- **One group shows no group chrome**, so the 1.0.0 screen barely changes for flat campaigns. The alternative, always showing cards, adds noise for the common case.
- **Each group has its own all/any, and so does the top.** That covers "A AND (B OR C)" and "(A AND B) OR (C AND D)" without repeating conditions.
- **A type may appear once per group** but again in another group (for example spend in USD in one group and in EUR in another, ORed). Within a group a repeat adds nothing, and the existing "one per type" check stays, scoped to the group.
- **Empty groups are dropped on save** rather than blocking the save. It's what the merchant means, and it keeps API callers from storing a group that matches nobody.
- **At most 10 groups.** It's enough for any real targeting, and it bounds evaluation on every order.
- **No reward conditions on the "Points discount" reward.** The program's excluded roles already gate who may spend points.

## Acceptance
- In a campaign's Conditions, add Country (US, billing) → "Match [all] of these conditions" appears above the row, and there's no group card.
- Click "Add group", add Lifetime spend at least 500 and Order count at least 5 to Group 2, set Group 2 to "any" → two cards, joined by "AND".
- Save and reload → the same two groups, Group 1 "all", Group 2 "any", the top "all". The campaigns table shows "Country AND (Lifetime spend OR Order count)".
- Remove Group 2 → the screen returns to the one-group layout. Save, then reload → the stored `conditions` column is the old `{"schema_version":1,"items":[…]}` list.
- Add an empty group and save → it's gone after reload. No error is shown.
- Add 10 groups → "Add group" is disabled and "Up to 10 groups." shows.
- Open a fixed-coupon reward → step 3 "Who can redeem" offers Customer role, Specific customers and Lifetime points. Open "Points discount" → there's no step 3.
- Save a reward limited to role "wholesale" → its Availability column says "Selected customers".
