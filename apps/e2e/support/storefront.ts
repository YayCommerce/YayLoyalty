import type { Locator, Page } from '@playwright/test';

import type { Api } from './api';

/** The points card, wherever the page draws it. */
export function card(page: Page): Locator {
  return page.locator('[data-yl-redeem]');
}

/** Opens the storefront launcher on the current page and returns its panel. */
export async function openLauncher(page: Page): Promise<Locator> {
  await page.locator('[data-yl-open]').click();
  const panel = page.getByRole('dialog', { name: 'Rewards' });
  await panel.waitFor();

  return panel;
}

/** Waits for the order-received page a placed order lands on, and returns the order's id. */
export async function placedOrderId(page: Page): Promise<number> {
  await page.waitForURL(/order-received\/(\d+)/);

  return Number(/order-received\/(\d+)/.exec(page.url())![1]);
}

/** Signed points of the customer's `redeem` and `refund_redeem` ledger rows, oldest first. */
export async function redeemRows(api: Api, customerId: number): Promise<number[]> {
  const rows = await api.ledger(customerId);

  return rows.filter((row) => row.type === 'redeem' || row.type === 'refund_redeem').map((row) => row.points);
}
