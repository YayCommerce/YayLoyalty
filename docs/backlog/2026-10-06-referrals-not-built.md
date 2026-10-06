# Referrals are not built

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-06 decision to ship v1.0 without referrals (`docs/plans/2026-10-06-remove-tiers-and-referrals.md`, branch `trace/chore/remove-tiers-referrals`).

## Today

v1.0 has no referral UI or logic. Only the stored shape stays:

- the `customers.referral_code` column, with `UNIQUE KEY referral_code` (`includes/Database/Schema/CustomersTable.php`). Nothing writes it.
- `CampaignTrigger::REFERRAL`, in `all_known()` but in neither build's offered triggers (`YayLoyaltyPro` docblock).
- `LedgerSource::REFERRAL` (the SPA labels it "Earned from a referral") and `ReferenceType::REFERRAL`, which points at a `yayloyalty_referrals` table that doesn't exist yet.
- The rule in `IdempotencyKey`'s docblock that a referral order pays two people, so the key holds the user.

That branch removed these placeholders. Restore them from its squash commit only as a starting point:

- the Referral page and nav item;
- `GET /customers/{id}/referrals` and the drawer's Referrals tab;
- `counts.referrals` and `loose_ends.credited_referrals` on `GET /customers/{id}`;
- the Customers list's disabled Referrals column and sort key;
- the dashboard's Referrals tile and its Lite teaser;
- the Design keys `storefront_text.referral_card_heading` and `panel.show_referral_card`;
- the Referral reward earned and Invite sent emails, and the Loyalty: Referral group.

## Why it matters

Referrals are in the plugin name. Until they ship, the plugin sells what it doesn't do.

## Suggested fix

Start from a spec. Referrals need the `yayloyalty_referrals` table, a code issued into `customers.referral_code`, and attribution from a friend's first order. They also need a fireable `REFERRAL` trigger that awards both people through `LedgerService`, with an idempotency key per user. Every surface above then reads real data.

## Done when

- [ ] The `yayloyalty_referrals` table exists, and a schema change ships it.
- [ ] Each customer gets a code in `customers.referral_code`, and a friend's signup and first order are attributed to it.
- [ ] `CampaignTrigger::REFERRAL` is offered and fires, awarding both people through `LedgerService` with `LedgerSource::REFERRAL`.
- [ ] Merchants configure the program on a Referral page.
- [ ] The drawer shows a customer's referrals, and `GET /customers/{id}` reports their counts.
- [ ] The dashboard shows completed referrals.
- [ ] The storefront shows the referral card, configured in Design.
- [ ] The referral and invite emails send on a real trigger.
