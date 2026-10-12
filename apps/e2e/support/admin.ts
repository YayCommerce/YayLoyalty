import type { Page } from '@playwright/test';

export async function openOrder(page: Page, orderId: number): Promise<void> {
  await page.goto(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
}

/** Changes the status on the order screen and saves, as a merchant would. $status is the slug without `wc-`. */
export async function setOrderStatusInAdmin(page: Page, orderId: number, status: string): Promise<void> {
  await openOrder(page, orderId);
  // select2 hides the real select.
  await page.locator('#order_status').selectOption(`wc-${status}`, { force: true });
  await page.getByRole('region', { name: 'Order actions' }).getByRole('button', { name: 'Update' }).click();
  await page.waitForLoadState();
}
