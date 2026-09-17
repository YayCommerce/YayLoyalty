# YayLoyalty — Project Changelog

Detailed record of significant features, improvements, bug fixes, and technical changes.

---

## 2026-09-17

### Added

**Campaign Editor UI (Pro-only)** — New admin screen at route `/campaigns/:id/edit` for editing campaigns, reachable from the Campaigns list via row name-link and Duplicate action. Features:
- Six-section form (Basics, Trigger, Reward, Conditions, Schedule, Priority) plus live-updating summary rail
- First adoption of react-hook-form + zodResolver in this codebase
- Per-trigger reward config editors for Lite triggers (purchase/signup/review); generic read-only fallback for Pro-only triggers, preserving config byte-for-byte on save
- 10-type condition builder (matching `CampaignConditionType::all()` exactly)
- Nullable start/end scheduling with site-timezone conversion at display/input edges (raw UTC on wire)
- Pro-only gating: `IS_PRO_BUILD && CAP.MANAGE_CAMPAIGNS` with Pro lock winning if both apply

Extended the consolidated campaign data layer (`campaign.schema.ts` / `campaign.api.ts` / `campaign.queries.ts`) with update-input schema, `updateCampaign()` call, and `useUpdateCampaignMutation()` hook. PHP-side: `CampaignRestController::update_item()` validates campaign (now wiring `Campaign::conditions_are_valid()` as a real gate, previously a stub) and returns it, but **does NOT persist to the database** — a deliberate, explicitly-marked SWAP POINT consistent with the pre-existing read-side SWAP POINT (list/detail from dummy fixture, not real table). Both SWAP POINTs are intended to be resolved together in a future CRUD task.

### Known Gaps (Flagged, Not Built)

- No product/category/customer search endpoint → raw-id `TagsInput` used instead of pickers (deferred)
- No store-wide ledger aggregate → "cost estimate" figure in summary rail omitted
- `updated_by` column never set by controller (out of scope)
- `include_shipping` config key still stored but never read by `BaseCalculator` (dead key)

---
