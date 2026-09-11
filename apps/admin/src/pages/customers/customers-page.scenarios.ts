import { CUSTOMERS_SCENARIOS_CORE } from './customers-page.scenarios-core';
import { CUSTOMERS_SCENARIOS_EDGE } from './customers-page.scenarios-edge';

export type { CustomersScenarioId, ScenarioConfig } from './customers-page.scenario-types';

/**
 * DEMO DATA ONLY — see `customers-page.mock-data.ts`. Barrel merging the 4
 * everyday states (`scenarios-core`) and the 3 edge states (`scenarios-edge`)
 * into the single lookup + order the page and its switcher consume.
 */
export const CUSTOMERS_SCENARIOS = {
  ...CUSTOMERS_SCENARIOS_CORE,
  ...CUSTOMERS_SCENARIOS_EDGE,
};

export const CUSTOMERS_SCENARIO_ORDER = [
  'free-list',
  'pro-list',
  'empty-store',
  'loading',
  'pro-at-scale',
  'no-match',
  'rebuilding',
] as const;
