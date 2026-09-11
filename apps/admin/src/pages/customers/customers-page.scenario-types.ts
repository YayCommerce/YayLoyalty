import type { MockCustomer } from './customers-page.mock-data';

/**
 * DEMO DATA ONLY — see `customers-page.mock-data.ts`. Shape shared by every
 * `ScenarioConfig` entry across `customers-page.scenarios-core.ts` and
 * `customers-page.scenarios-edge.ts`.
 */
export type CustomersScenarioId =
  | 'free-list'
  | 'pro-list'
  | 'empty-store'
  | 'loading'
  | 'pro-at-scale'
  | 'no-match'
  | 'rebuilding';

export interface ToolbarConfig {
  mode: 'idle' | 'chips' | 'inert';
  /** Typed-but-not-submitted search text, shown only in `chips` mode. */
  searchValue?: string;
  chips?: string[];
  exportDisabled: boolean;
  showTierFilter: boolean;
  showNegativeFilter: boolean;
}

export interface EmptyCopy {
  title: string;
  description: string;
  links?: { label: string; to: string }[];
}

export interface ScenarioConfig {
  id: CustomersScenarioId;
  label: string;
  /** Needs a Pro build to render its full surface (Lite shows the upgrade overlay). */
  isPro: boolean;
  body: 'rows' | 'loading' | 'empty-store' | 'no-match';
  rows: MockCustomer[];
  /** `null` = skeleton — a real "0" would be a wrong answer while still loading. */
  memberCount: string | null;
  toolbar: ToolbarConfig;
  /** Tier/Referrals columns + the leading checkbox column. */
  showProColumns: boolean;
  selection: 'none' | 'page-partial' | 'page-all';
  bulkBar?: { selectedLabel: string; coversPageNote?: string; selectAllLabel?: string };
  savedSegment?: { valueLabel: string; saveLinkLabel: string };
  approxCount?: { headline: string; note: string; actionLabel: string };
  rebuild?: {
    title: string;
    detail: string;
    percent: number;
    watchLogLabel: string;
    stopLabel: string;
    safetyNote: string;
  };
  staleBalances: boolean;
  emptyCopy?: EmptyCopy;
  suggestions?: { withoutLabel: string; countLabel: string }[];
  infoPanels?: { title: string; lines: string[] }[];
  pagination: { rangeLabel: string; note?: string; previousDisabled: boolean; nextDisabled: boolean };
  upsellFooter: boolean;
}

export const IDLE_TOOLBAR: ToolbarConfig = {
  mode: 'idle',
  exportDisabled: false,
  showTierFilter: false,
  showNegativeFilter: false,
};
