import { __ } from '@wordpress/i18n';

import { MOCK_CUSTOMERS, MOCK_CUSTOMERS_AT_SCALE } from './customers-page.mock-rows';
import { IDLE_TOOLBAR, type ScenarioConfig } from './customers-page.scenario-types';

/**
 * DEMO DATA ONLY — see `customers-page.mock-data.ts`. The 3 edge states: a
 * 250k-member Pro store, a filter combination that matches nobody, and the
 * list while a balance rebuild is running. Split from
 * `customers-page.scenarios-core.ts` to keep both files under the project's
 * 200-line file cap.
 */

export const CUSTOMERS_SCENARIOS_EDGE = {
  'pro-at-scale': {
    id: 'pro-at-scale',
    label: __('Pro at scale', 'yayloyalty'),
    isPro: true,
    body: 'rows',
    rows: MOCK_CUSTOMERS_AT_SCALE,
    memberCount: __('250,000 program members', 'yayloyalty'),
    toolbar: { ...IDLE_TOOLBAR, showTierFilter: true, showNegativeFilter: true },
    showProColumns: true,
    selection: 'page-all',
    bulkBar: {
      selectedLabel: __('20 selected on this page', 'yayloyalty'),
      coversPageNote: __('Selection covers this page only.', 'yayloyalty'),
      selectAllLabel: __('Select all 11,100 customers matching these filters', 'yayloyalty'),
    },
    savedSegment: {
      valueLabel: __('Alpine members, active this week', 'yayloyalty'),
      saveLinkLabel: __('Save current filters as segment', 'yayloyalty'),
    },
    approxCount: {
      headline: __('About 11,100 customers match these filters.', 'yayloyalty'),
      note: __('Counted hourly · exact for the page you are on', 'yayloyalty'),
      actionLabel: __('Count now', 'yayloyalty'),
    },
    staleBalances: false,
    pagination: {
      rangeLabel: __('1–20 of about 11,100', 'yayloyalty'),
      note: __('Page numbers stop at 100 — narrow the filters to go deeper', 'yayloyalty'),
      previousDisabled: true,
      nextDisabled: false,
    },
    upsellFooter: false,
  },
  'no-match': {
    id: 'no-match',
    label: __('No match', 'yayloyalty'),
    isPro: true,
    body: 'no-match',
    rows: [],
    memberCount: __('2,431 program members', 'yayloyalty'),
    toolbar: {
      mode: 'chips',
      searchValue: 'bergstr',
      chips: [
        __('Balance 5,000–10,000', 'yayloyalty'),
        __('Tier: Alpine', 'yayloyalty'),
        __('Expiring in 30 days', 'yayloyalty'),
      ],
      exportDisabled: true,
      showTierFilter: false,
      showNegativeFilter: false,
    },
    showProColumns: true,
    selection: 'none',
    savedSegment: {
      valueLabel: __('None — three filters applied', 'yayloyalty'),
      saveLinkLabel: __('Save these filters as a segment', 'yayloyalty'),
    },
    staleBalances: false,
    emptyCopy: {
      title: __('No customers match all four filters', 'yayloyalty'),
      description: __(
        'Alpine members all hold more than 10,000 points, so the tier and the balance range cannot both be true. Removing either one gives results.',
        'yayloyalty',
      ),
    },
    suggestions: [
      { withoutLabel: __('Tier: Alpine', 'yayloyalty'), countLabel: __('37 customers', 'yayloyalty') },
      { withoutLabel: __('Balance 5,000–10,000', 'yayloyalty'), countLabel: __('4 customers', 'yayloyalty') },
      { withoutLabel: __('Search "bergstr" on its own', 'yayloyalty'), countLabel: __('1 customer', 'yayloyalty') },
    ],
    infoPanels: [
      {
        title: __('Not the same screen as an empty programme', 'yayloyalty'),
        lines: [
          __(
            'A store with no members yet gets the first-run screen with a link to the setup wizard. This one has 2,431 members and a contradictory query, so it explains the contradiction instead and never suggests setting anything up.',
            'yayloyalty',
          ),
        ],
      },
    ],
    pagination: { rangeLabel: __('0 of 2,431', 'yayloyalty'), previousDisabled: true, nextDisabled: true },
    upsellFooter: false,
  },
  rebuilding: {
    id: 'rebuilding',
    label: __('Rebuilding', 'yayloyalty'),
    isPro: true,
    body: 'rows',
    rows: MOCK_CUSTOMERS.slice(0, 5),
    memberCount: __('2,431 program members', 'yayloyalty'),
    toolbar: { ...IDLE_TOOLBAR, mode: 'inert', exportDisabled: true, showTierFilter: true },
    showProColumns: true,
    selection: 'none',
    rebuild: {
      title: __('Balances are being rebuilt — 1,240 of 2,431 checked', 'yayloyalty'),
      detail: __(
        'Started 11:02 by Sarah Chen from Tools. The figures below are as they were at 11:02 and will not update until it finishes, in about two minutes. 3 balances corrected so far.',
        'yayloyalty',
      ),
      percent: 51,
      watchLogLabel: __('Watch the log', 'yayloyalty'),
      stopLabel: __('Stop the rebuild', 'yayloyalty'),
      safetyNote: __('Stopping is safe: it never writes a partial balance, only whole customers.', 'yayloyalty'),
    },
    staleBalances: true,
    infoPanels: [
      {
        title: __('What is still allowed', 'yayloyalty'),
        lines: [
          __('Opening a customer — the drawer shows live figures, not the 11:02 snapshot', 'yayloyalty'),
          __('Adjusting points — the rebuild reads the ledger, so a new row is picked up', 'yayloyalty'),
          __('Earning and redeeming — the storefront is unaffected throughout', 'yayloyalty'),
        ],
      },
      {
        title: __('What waits', 'yayloyalty'),
        lines: [
          __('Sorting and filtering by balance — they would use stale numbers', 'yayloyalty'),
          __('Export — a file stamped 11:02 is worse than no file', 'yayloyalty'),
          __('Bulk actions — queued until the rebuild finishes', 'yayloyalty'),
        ],
      },
    ],
    pagination: {
      rangeLabel: __('1–20 of 2,431', 'yayloyalty'),
      note: __('· balances as of 11:02', 'yayloyalty'),
      previousDisabled: true,
      nextDisabled: false,
    },
    upsellFooter: false,
  },
} satisfies Record<string, ScenarioConfig>;
