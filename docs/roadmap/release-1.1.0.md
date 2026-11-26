# Release 1.1.0

[Roadmap](README.md) · [Items in 1.1.0](v1.1.0.md)

<!-- Written by hand: `yayh roadmap` never rewrites this file. Use plain words a leader or support can read: what people see, not class or table names. -->

## Goal
<!-- One paragraph: what this release is for, who gets what, and why these items. -->
1.1.0 turns YayLoyalty from a points-for-orders plugin into a full loyalty program. Merchants get finer targeting (a campaign or reward can match any or all of its conditions), VIP tiers that reward their best customers, a referral program that brings in new ones, and more ways to earn (social actions and subscription renewals). Store builders can place the points widgets in Elementor, Bricks and Divi, stores moving from another loyalty plugin can bring their balances in with CSV import and adjust points in bulk, and the free shipping and free product rewards Pro already offered can now be redeemed.

## Release criteria
<!-- Each line is something that must be true to ship, checked by /yay:release before it tags. Tick it when it's verified, with where (staging, a test site, the e2e run). -->
- [ ] Every item in this version works end to end on a test site, for every edition it names
- [ ] Campaigns and rewards saved in 1.0.0 behave the same after upgrading to 1.1.0 (their conditions still all have to match)
- [ ] A customer's tier goes up when the points they earned in the qualifying period cross a threshold, and redeeming points never lowers it; it drops back when a refund of an order in their current qualifying period takes them under it, while a customer kept at their tier (raised threshold, mode switch, hold) keeps it until its next review
- [ ] A referral pays both customers only after the friend's first order completes; self-referrals and refunded orders earn nothing
- [ ] The widgets render in Elementor, Bricks and Divi the same as the shortcodes, and a site without those builders loads none of their code
- [ ] A subscription renewal earns points once, and a refunded renewal takes them back
- [ ] CSV import shows a preview and per-row errors before anything is written, and every imported or bulk change appears in the customer's history
- [ ] A merchant can add free shipping and free product rewards; a shopper redeems them on classic and block checkout, a reward the cart can't use says why, and cancelling or fully refunding the order gives the points back
- [ ] Free rewards behave with YayPricing and YayCurrency active (the free line isn't priced by YayPricing; the freed value is stored in the order's currency)
- [ ] Lite contains no Pro-only feature (tiers, referrals, the any-or-all condition match, social/renewal earning, free shipping and free product rewards), and the Lite zip passes the build validator
- [ ] Upgrading from 1.0.0 keeps every balance and ledger row

## Out of scope
<!-- What people might expect in this release, and where it goes instead (a later version, Later, dropped). -->
- Per-product or per-category earning rates and time-limited multipliers ("2x points this weekend"): Later.
- Page builders other than Elementor, Bricks and Divi (Beaver Builder, Oxygen, Breakdance): Later.
- Recalculating balances, awarding points for past orders, resetting program data: Later (maintenance tools).
- Cut first if late: R-5 (more ways to earn), then the Divi part of R-3.

## Risks
<!-- What could go wrong for merchants or shoppers, and what limits it. -->
| Risk | Impact | Mitigation |
|---|---|---|
| The any-or-all match changes how existing campaigns match | Customers earn or lose points they shouldn't | Keep 1.0.0 lists stored as they were and read them as all; upgrade test against 1.0.0 data |
| Tier recalculation on large stores | Slow admin or cron timeouts | Recalculate in Action Scheduler batches; evaluate on order completion, not on every page load |
| Referral abuse (self-referrals, fake accounts) | Points given away for nothing | Pay only after the friend's first completed order; block same email, address and payment; hold points until the refund window passes |
| Builder updates break the widgets | Broken pages on merchants' sites | Thin widgets that call the shortcode renderers; load each only when its builder is active |
| CSV import writes wrong balances | Hard-to-undo point changes | Preview and dry run first; each import is one ledger batch that can be reversed |
| Year-end holidays fall in sprint 4 (referrals) | Sprint 4 slips, release moves past 2027-01-21 | Referrals are the riskiest L in a short sprint; R-5 is the first cut to win the time back |

## Open questions
<!-- Each has a proposal that the work follows until someone answers it. Fill Answer (with who and when) once it's decided. -->
| # | Question | Proposal | Answer |
|---|---|---|---|
| 1 | Are VIP tiers and referrals Pro only? | Yes: tiers, referrals, the any-or-all condition match and new earning actions are Pro; widgets and Tools ship in both editions | |
| 2 | What does "more ways to earn" cover? | Social follow/share and WooCommerce Subscriptions renewals (the triggers the code already reserves) | |
| 3 | Do tiers count lifetime spend, lifetime points, or both? | Merchant picks one per program; lifetime spend is the default | Points earned in the qualifying period only (all time, last N months, or calendar period); spend isn't counted, and redeeming points never lowers a tier. Decided 2026-11-23. |
| 4 | Should 1.1.0 avoid shipping across BFCM (late November)? | No freeze: work continues through BFCM and 1.1.0 ships after sprint 2 ends (2026-12-10) | |
| 5 | Should the order screen and emails label a free product line with its reward (BL-26)? | No: only cart and checkout say "Reward: …"; the order shows the product at 0 and the reward's coupon row | |
| 6 | When an admin removes a free reward's coupon from an order and restores the points, should the gift be charged (BL-27)? | No: the points come back and the order keeps the free line and free shipping, as for a coupon reward's discount | |
