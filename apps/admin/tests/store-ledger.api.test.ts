import { beforeEach, describe, expect, it, vi } from 'vitest';

import { api } from '@/lib/api/api';
import { getStoreLedger } from '@/pro/ledger/store-ledger.api';

// The real client reads `window` when it loads; only the request it is handed matters here.
vi.mock('@/lib/api/api', () => ({ api: { get: vi.fn() } }));

const EMPTY_PAGE = {
  currentPage: 1,
  totalPage: 0,
  totalItems: 0,
  perPage: 20,
  data: [],
  type_counts: { all: 0, earn: 0, redeem: 0, adjust: 0, expire: 0 },
};

/** The search params of the one GET /ledger call the client made. */
function sentParams(): Record<string, string> {
  const get = vi.mocked(api.get);

  expect(get).toHaveBeenCalledTimes(1);
  expect(get.mock.calls[0][0]).toBe('ledger');

  return (get.mock.calls[0][1] as { searchParams: Record<string, string> }).searchParams;
}

beforeEach(() => {
  vi.mocked(api.get).mockReset();
  vi.mocked(api.get).mockReturnValue({ json: async () => EMPTY_PAGE } as unknown as ReturnType<typeof api.get>);
});

describe('getStoreLedger', () => {
  it('sends every filter in its wire spelling', async () => {
    await getStoreLedger({
      page: 2,
      perPage: 20,
      search: '  ada@example.com  ',
      after: '2026-10-01',
      before: '2026-10-12',
      type: 'redeem',
      campaignId: 15,
      order: 'asc',
    });

    expect(sentParams()).toEqual({
      page: '2',
      per_page: '20',
      search: 'ada@example.com',
      after: '2026-10-01',
      before: '2026-10-12',
      type: 'redeem',
      campaign_id: '15',
      order: 'asc',
    });
  });

  it('leaves out the "all" type, an unset campaign and a blank search', async () => {
    await getStoreLedger({ page: 1, perPage: 20, search: '   ', type: 'all', campaignId: undefined });

    expect(sentParams()).toEqual({ page: '1', per_page: '20' });
  });

  it('rejects a response that is not the ledger envelope', async () => {
    vi.mocked(api.get).mockReturnValue({
      json: async () => ({ ...EMPTY_PAGE, type_counts: undefined }),
    } as unknown as ReturnType<typeof api.get>);

    await expect(getStoreLedger()).rejects.toThrow();
  });
});
