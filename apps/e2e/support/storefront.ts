import { expect } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

import type { Api } from './api';

/** The points card, wherever the page draws it. */
export function card(page: Page): Locator {
  return page.locator('[data-yl-redeem]');
}

/** The block totals' discount rows. The card itself also reads "Points discount", as its row's name. */
export function blockDiscounts(page: Page): Locator {
  return page.locator('.wc-block-components-totals-discount');
}

/** The block totals' order total. */
export function blockTotal(page: Page): Locator {
  return page.locator('.wc-block-components-totals-footer-item');
}

/** The card's "Points to spend" number field. */
export function pointsField(page: Page): Locator {
  return card(page).getByRole('spinbutton', { name: 'Points to spend' });
}

/** The card's Points discount checkbox, named by its row: "Points discount Flexible amount". */
export function pointsDiscountBox(page: Page): Locator {
  return card(page).getByRole('checkbox', { name: /^Points discount/ });
}

/** Types the points into the card's field and presses Enter; on the cart that applies them after the debounce. */
export async function setPoints(page: Page, points: number): Promise<void> {
  await pointsField(page).fill(String(points));
  await pointsField(page).press('Enter');
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

/** A reward's checkbox, named by its row: "$5 off 500 Points …". */
export function rewardBox(page: Page, name: string): Locator {
  return card(page).getByRole('checkbox', { name: new RegExp(`^${name.replace(/\$/g, '\\$')}`) });
}

/** Five E2E Widgets: a $100 cart. */
export async function addWidgetsToCart(page: Page): Promise<void> {
  await page.goto('/product/e2e-widget/');
  await page.locator('input.qty').fill('5');
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click();
  await expect(page.getByText('have been added to your cart')).toBeVisible();
}
