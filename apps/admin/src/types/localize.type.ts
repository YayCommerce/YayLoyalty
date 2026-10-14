import type { CustomerBalanceRange, CustomerExpiringSoon } from '@/lib/schema/customer.schema';
import { Design } from '@/lib/schema/design.schema';
import { Settings } from '@/lib/schema/settings.schema';

/** Mirrors LocalizeHelper::get_meta(), the source of truth. */
export type Meta = {
  wpMeta: {
    siteUrl: string;
    adminUrl: string;
    ajaxUrl: string;

    restRoot: string;
    restBase: string;
    restNonce: string;
    isBlockTheme: boolean;
    /** Slug => translated display name, e.g. `{ shop_manager: 'Shop Manager' }`. */
    roles: Record<string, string>;
  };
  loyaltyMeta: {
    pluginUrl: string;
    assetsUrl: string;

    version: string;
    // No pro flag by design: the SPA is gated at build time.
    reviewed: boolean;
    /** False until the setup wizard is finished or exited. */
    setupCompleted: boolean;
    /** ExpiryPolicy::GRACE_DAYS: a settings change never sets an expiry date sooner than this many days from now. */
    expiryGraceDays: number;
  };
  wcMeta: {
    currency_data: {
      currency: string;
      symbol: string;
      position: string;
      thousand_sep: string;
      decimal_sep: string;
      num_decimals: number;
    };
    /** Every registered gateway, id => admin title. */
    payment_gateways: Record<string, string>;
    /** ISO country code => name. */
    countries: Record<string, string>;
    /** ISO 4217 code => name. */
    currencies: Record<string, string>;
    /** WooCommerce → Settings → General → "Enable the use of coupon codes"; points redemption depends on it. */
    coupons_enabled: boolean;
  };
};

export type AdminData = {
  settings: Settings;
  design: Design;
  /** From Capabilities::payload(). Cosmetic only; every route re-checks server-side. */
  capabilities: string[];
  /** Saved segments, Pro-only hence optional. Read via readSegmentsFromLocalize(), never directly. */
  segments?: {
    name: string;
    filters: { balance_range: CustomerBalanceRange; expiring_soon: CustomerExpiringSoon };
  }[];
};
