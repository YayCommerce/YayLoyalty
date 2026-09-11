import { __ } from '@wordpress/i18n';

import { MOCK_CUSTOMERS } from './customers-page.mock-rows';
import { IDLE_TOOLBAR, type ScenarioConfig } from './customers-page.scenario-types';

/**
 * DEMO DATA ONLY — see `customers-page.mock-data.ts`. The 4 everyday states:
 * the Free list, the Pro list, a brand-new store, and the list while loading.
 * Split from `customers-page.scenarios-edge.ts` to keep both files under the
 * project's 200-line file cap.
 */

const memberCountLabel = (count: string) => __(`${count} program members`, 'yayloyalty');

export const CUSTOMERS_SCENARIOS_CORE = {
  'free-list': {
    id: 'free-list',
    label: __('Free', 'yayloyalty'),
    isPro: false,
    body: 'rows',
    rows: MOCK_CUSTOMERS,
    memberCount: memberCountLabel('2,431'),
    toolbar: IDLE_TOOLBAR,
    showProColumns: false,
    selection: 'none',
    staleBalances: false,
    pagination: { rangeLabel: __('1–20 of 2,431', 'yayloyalty'), previousDisabled: true, nextDisabled: false },
    upsellFooter: true,
  },
  'pro-list': {
    id: 'pro-list',
    label: __('Pro', 'yayloyalty'),
    isPro: true,
    body: 'rows',
    rows: MOCK_CUSTOMERS,
    memberCount: memberCountLabel('2,431'),
    toolbar: { ...IDLE_TOOLBAR, showTierFilter: true },
    showProColumns: true,
    selection: 'page-partial',
    bulkBar: { selectedLabel: __('3 selected', 'yayloyalty') },
    savedSegment: {
      valueLabel: __('Summit members inactive 90 days', 'yayloyalty'),
      saveLinkLabel: __('Save current filters as segment', 'yayloyalty'),
    },
    staleBalances: false,
    pagination: { rangeLabel: __('1–20 of 2,431', 'yayloyalty'), previousDisabled: true, nextDisabled: false },
    upsellFooter: false,
  },
  'empty-store': {
    id: 'empty-store',
    label: __('New store', 'yayloyalty'),
    isPro: false,
    body: 'empty-store',
    rows: [],
    memberCount: memberCountLabel('0'),
    toolbar: { ...IDLE_TOOLBAR, mode: 'inert', exportDisabled: true },
    showProColumns: false,
    selection: 'none',
    staleBalances: false,
    emptyCopy: {
      title: __('No members yet', 'yayloyalty'),
      description: __(
        'A customer appears here the first time they earn points — from an order, an account signup or a product review.',
        'yayloyalty',
      ),
      links: [
        { label: __('Check campaigns', 'yayloyalty'), to: '/campaigns' },
        { label: __('Check rewards', 'yayloyalty'), to: '/rewards' },
      ],
    },
    pagination: { rangeLabel: __('0 of 0', 'yayloyalty'), previousDisabled: true, nextDisabled: true },
    upsellFooter: true,
  },
  loading: {
    id: 'loading',
    label: __('Loading', 'yayloyalty'),
    isPro: false,
    body: 'loading',
    rows: [],
    memberCount: null,
    toolbar: IDLE_TOOLBAR,
    showProColumns: false,
    selection: 'none',
    staleBalances: false,
    pagination: { rangeLabel: __('1–20 of', 'yayloyalty'), previousDisabled: true, nextDisabled: true },
    upsellFooter: true,
  },
} satisfies Record<string, ScenarioConfig>;
