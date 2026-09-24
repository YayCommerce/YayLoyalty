import type { CustomerBalanceRange, CustomerExpiringSoon, CustomerTier } from '@/lib/schema/customer.schema';
import { Design } from '@/lib/schema/design.schema';
import { EmailsResponse } from '@/lib/schema/emails.schema';
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
    /** Slug => translated display name, e.g. `{ shop_manager: 'Shop Manager' }`. */
    roles: Record<string, string>;
  };
  loyaltyMeta: {
    pluginUrl: string;
    assetsUrl: string;

    version: string;
    // No pro flag by design: the SPA is gated at build time.
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
  design: Design;
  emails: EmailsResponse;
  /** From Capabilities::payload(). Cosmetic only; every route re-checks server-side. */
  capabilities: string[];
  /** Per-adjustment limit for non-administrators; 0 = unlimited. */
  ceiling: number;
  isAdministrator: boolean;
  /** Saved segments, Pro-only hence optional. Read via readSegmentsFromLocalize(), never directly. */
  segments?: {
    name: string;
    filters: { balance_range: CustomerBalanceRange; expiring_soon: CustomerExpiringSoon; tier: CustomerTier };
  }[];
};
