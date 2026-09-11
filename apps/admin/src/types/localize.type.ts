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
};
