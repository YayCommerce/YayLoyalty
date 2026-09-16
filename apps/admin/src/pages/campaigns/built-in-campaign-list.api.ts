import type { Campaign } from '@/lib/schema/campaign.schema';

/**
 * SWAP POINT — this is the whole seam. Replace the body with:
 *   return (await getCampaigns({ per_page: 100 })).data;
 * using getCampaigns from @/lib/api/campaign.api (already real, already
 * working — it's what the old read-only Lite list used). Nothing else in
 * this file or in BuiltInCampaignList.tsx needs to change: same return type,
 * same call site.
 *
 * Fixture fields are typed as `Campaign` (not a local shape) specifically so
 * this can't quietly drift from what CampaignRestController actually returns.
 */
export async function getBuiltInCampaigns(): Promise<Campaign[]> {
  return BUILT_IN_CAMPAIGNS;
}

/**
 * The three built-ins. `name`/`slug`/`trigger`/`priority` copied verbatim
 * from Installer::seed_builtins() — every site gets exactly these three, by
 * these slugs, and `is_builtin` rows are matched by slug across the app (see
 * that method's docblock), so a fixture slug that drifted from the real one
 * would silently stop being "the same campaign" once this swaps to real data.
 *
 * `config` matches `config_schema()` in Models\Campaign, checked field by
 * field against the PHP, not the older wireframe, which by now disagrees
 * with the schema in a few places: no per-campaign "award on order status"
 * field exists; `rounding` has a third value, `up`; `include_shipping` /
 * `include_tax` are real keys the wireframe never showed.
 * `max_points_per_order`, `daily_cap` and `media_bonus_points` are real keys
 * too, but Lite's own docblock says it stores without acting on them, so
 * BuiltInCampaignList renders no field for them.
 */
const BUILT_IN_CAMPAIGNS: Campaign[] = [
  {
    id: 1,
    name: 'Points for purchases',
    slug: 'default-purchase',
    trigger: 'purchase',
    status: 'active',
    priority: 0,
    config: {
      points_per_unit: 1,
      base: 'after_discounts',
      include_shipping: false,
      include_tax: false,
      rounding: 'down',
      min_subtotal: 0,
      exclude_coupon_orders: false,
      excluded_product_ids: [],
      excluded_category_ids: [],
    },
    conditions: [],
    starts_at: null,
    ends_at: null,
    is_builtin: true,
    created_by: null,
    created_at: '2026-01-01T00:00:00',
    updated_at: '2026-09-04T00:00:00',
  },
  {
    id: 2,
    name: 'Points for signing up',
    slug: 'signup',
    trigger: 'signup',
    status: 'active',
    priority: 0,
    config: { points: 100 },
    conditions: [],
    starts_at: null,
    ends_at: null,
    is_builtin: true,
    created_by: null,
    created_at: '2026-01-01T00:00:00',
    updated_at: '2026-01-01T00:00:00',
  },
  {
    id: 3,
    name: 'Points for writing a review',
    slug: 'review',
    trigger: 'review',
    status: 'active',
    priority: 0,
    config: { points: 50, require_verified: true, one_per_product: true, daily_cap: 0 },
    conditions: [],
    starts_at: null,
    ends_at: null,
    is_builtin: true,
    created_by: null,
    created_at: '2026-01-01T00:00:00',
    updated_at: '2026-01-01T00:00:00',
  },
];
