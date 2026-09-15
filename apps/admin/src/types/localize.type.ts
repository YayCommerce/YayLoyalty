import type { CustomerBalanceRange, CustomerExpiringSoon, CustomerTier } from '@/lib/schema/customer.schema';
import { Settings } from '@/lib/schema/settings.schema';

export type Meta = {
  wpMeta: {
    siteUrl: string;
    adminUrl: string;
    ajaxUrl: string;

    restRoot: string;
    restBase: string;
    restNonce: string;
    isBlockTheme: boolean;
  };
  loyaltyMeta: {
    pluginUrl: string;
    assetsUrl: string;

    version: string;
    /*
     * No pro flag here by design. The SPA is gated at build time by
     * IS_PRO_BUILD (@/lib/is-pro-build), not by anything PHP sends.
     */
    reviewed: boolean;
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
  };
};

export type AdminData = {
  settings: Settings;
  /**
   * From YayLoyalty\Access\Capabilities::payload(). Cosmetic input for
   * hide/disable decisions only; every REST route re-checks on the server.
   */
  capabilities: string[];
  /** Per-adjustment limit for non-administrators; 0 = unlimited. */
  ceiling: number;
  isAdministrator: boolean;
  /**
   * Saved Customers-page filter sets. Pro-only — absent on a Lite install,
   * hence optional. Source of truth: Pro\Helpers\CustomerSegmentsHelper.
   * Read via `@/pro`'s `readSegmentsFromLocalize()`, never directly — this
   * type only makes `window.yayLoyaltyAdmin.segments` type-check; the value
   * unions come from the shared schema, not from `@/pro`, so this file pulls
   * in nothing Pro-only.
   */
  segments?: {
    name: string;
    filters: { balanceRange: CustomerBalanceRange; expiringSoon: CustomerExpiringSoon; tier: CustomerTier };
  }[];
};
