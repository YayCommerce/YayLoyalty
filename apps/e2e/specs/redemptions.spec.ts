import type { Locator, Page } from '@playwright/test';

import { openOrder, setOrderStatusInAdmin, untilReloaded } from '../support/admin';
import type { Api } from '../support/api';
import { test, expect } from '../support/fixtures';

/** The points card, wherever the page draws it. */
function card(page: Page): Locator {
  return page.locator('[data-yl-redeem]');
}

async function addWidgetToCart(page: Page): Promise<void> {
  await page.goto('/product/e2e-widget/');
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click();
  await expect(page.getByText('has been added to your cart')).toBeVisible();
}

async function applyPoints(page: Page, points: number): Promise<void> {
  await card(page).getByRole('spinbutton', { name: 'Points to spend' }).fill(String(points));
  await card(page).getByRole('button', { name: 'Apply' }).click();
}

/** Places the order with Check payments and returns its id. */
async function placeOrder(page: Page): Promise<number> {
  await page.getByLabel('Check payments').check();
  await page.getByRole('button', { name: 'Place Order' }).click();
  await page.waitForURL(/order-received\/(\d+)/);

  return Number(/order-received\/(\d+)/.exec(page.url())![1]);
}

async function redeemRows(api: Api, customerId: number): Promise<number[]> {
  const rows = await api.ledger(customerId);

  return rows.filter((row) => row.type === 'redeem' || row.type === 'refund_redeem').map((row) => row.points);
}

test('a shopper spends points on the block checkout and a cancellation gives them back', async ({
  api,
  customer,
  shopperPage,
  adminPage,
}) => {
  await api.adjustPoints(customer.id, 1000);
  await addWidgetToCart(shopperPage);

  await test.step('the cart card applies the points and takes them off again', async () => {
    await shopperPage.goto('/cart/');
    await expect(card(shopperPage)).toContainText('You have 1,000 Points ($10)');

    await applyPoints(shopperPage, 500);
    await expect(card(shopperPage)).toContainText('500 Points applied — $5 off');
    await expect(card(shopperPage)).toContainText('500 Points remaining');
    await expect(shopperPage.getByText('Points discount', { exact: true })).toBeVisible();

    await card(shopperPage).getByRole('button', { name: 'Remove' }).click();
    await expect(card(shopperPage).getByRole('button', { name: 'Apply' })).toBeVisible();
    await expect(shopperPage.getByText('Points discount', { exact: true })).toHaveCount(0);
    await api.expectPoints(customer.id, { balance: 1000 });
  });

  let orderId = 0;

  await test.step('the checkout card applies 500 points and the order debits them', async () => {
    await shopperPage.goto('/checkout/');
    await applyPoints(shopperPage, 500);
    await expect(card(shopperPage)).toContainText('500 Points applied — $5 off');
    orderId = await placeOrder(shopperPage);

    await api.expectPoints(customer.id, { balance: 500 });
    expect(await redeemRows(api, customer.id)).toEqual([-500]);
    expect(await api.redemptions(customer.id)).toMatchObject([
      { order_id: orderId, points: -500, value: '5.0000', status: 'applied' },
    ]);
    expect(await api.get(`wc/v3/orders/${orderId}`)).toMatchObject({ discount_total: '5.00', total: '15.00' });
  });

  await test.step('the admin sees the redemption in the customer drawer', async () => {
    await adminPage.goto(`/wp-admin/admin.php?page=yayloyalty#/customers/${customer.id}`);
    const drawer = adminPage.getByRole('dialog');
    await drawer.getByRole('tab', { name: 'Redemptions' }).click();

    const row = drawer.getByRole('row', { name: /Cart discount/ });
    await expect(row).toContainText('-500');
    await expect(row).toContainText(`#${orderId}`);
  });

  await test.step('cancelling the order restores the points', async () => {
    await setOrderStatusInAdmin(adminPage, orderId, 'cancelled');

    await api.expectPoints(customer.id, { balance: 1000 });
    expect(await redeemRows(api, customer.id)).toEqual([-500, 500]);
    expect(await api.redemptions(customer.id)).toMatchObject([{ status: 'cancelled' }]);
  });
});

test('a shopper spends points on the classic cart and checkout and a full refund gives them back', async ({
  api,
  customer,
  shopperPage,
  adminPage,
}) => {
  // WooCommerce confirms refunds with window.confirm().
  adminPage.on('dialog', (dialog) => dialog.accept());

  await api.adjustPoints(customer.id, 1000);
  await addWidgetToCart(shopperPage);

  await test.step("WooCommerce's own Remove on the cart's discount row clears the points", async () => {
    await shopperPage.goto('/e2e-classic-cart/');
    await applyPoints(shopperPage, 500);
    await expect(card(shopperPage)).toContainText('500 Points applied — $5 off');

    const discount = shopperPage.locator('.cart_totals tr.cart-discount');
    await expect(discount).toContainText('Points discount');
    await discount.getByRole('button', { name: /Remove/ }).click();

    await expect(card(shopperPage).getByRole('button', { name: 'Apply' })).toBeVisible();
    await expect(discount).toHaveCount(0);
  });

  let orderId = 0;

  await test.step('the checkout card applies 500 points and the order debits them', async () => {
    // The checkout redraws the order review, card included, once on load: a number typed before that is lost.
    const reviewed = shopperPage.waitForResponse(/wc-ajax=update_order_review/);
    await shopperPage.goto('/e2e-classic-checkout/');
    await reviewed;
    await expect(shopperPage.locator('.blockUI')).toHaveCount(0);
    await applyPoints(shopperPage, 500);
    await expect(card(shopperPage)).toContainText('500 Points applied — $5 off');
    await expect(shopperPage.locator('#order_review tr.cart-discount')).toContainText('Points discount');

    await shopperPage.locator('#payment_method_cheque').check({ force: true });
    await shopperPage.getByRole('button', { name: 'Place order' }).click();
    await shopperPage.waitForURL(/order-received\/(\d+)/);
    orderId = Number(/order-received\/(\d+)/.exec(shopperPage.url())![1]);

    await api.expectPoints(customer.id, { balance: 500 });
    expect(await redeemRows(api, customer.id)).toEqual([-500]);
    expect(await api.redemptions(customer.id)).toMatchObject([
      { order_id: orderId, points: -500, value: '5.0000', status: 'applied' },
    ]);
  });

  await test.step('the admin refunds the whole order and the points come back', async () => {
    await openOrder(adminPage, orderId);
    await adminPage.getByRole('button', { name: 'Refund', exact: true }).click();
    const widget = adminPage.locator('#order_line_items tr.item', { hasText: 'E2E Widget' });
    await widget.locator('input.refund_order_item_qty').fill('1');
    await widget.locator('input.refund_order_item_qty').dispatchEvent('change');
    await untilReloaded(adminPage, () => adminPage.getByRole('button', { name: /Refund \$15\.00 manually/ }).click());

    await api.expectPoints(customer.id, { balance: 1000 });
    expect(await redeemRows(api, customer.id)).toEqual([-500, 500]);
    expect(await api.redemptions(customer.id)).toMatchObject([{ status: 'refunded' }]);
  });
});

test('the card offers no more than the balance and the cart allow', async ({ page, api, customer, shopperPage }) => {
  await test.step('a guest gets the sign-in line and nothing to apply', async () => {
    await addWidgetToCart(page);
    await page.goto('/cart/');

    await expect(card(page)).toContainText('Sign in to spend your Points.');
    await expect(card(page).getByRole('button', { name: 'Apply' })).toHaveCount(0);
  });

  await addWidgetToCart(shopperPage);

  await test.step('a balance below the 100-point minimum cannot be applied', async () => {
    await api.adjustPoints(customer.id, 50);
    await shopperPage.goto('/cart/');

    await expect(card(shopperPage)).toContainText('You have 50 Points');
    await expect(card(shopperPage).getByRole('button', { name: 'Apply' })).toBeDisabled();
    await expect(card(shopperPage).getByRole('slider')).toBeDisabled();
  });

  await test.step('a balance above the cart total is capped at the cart', async () => {
    await api.adjustPoints(customer.id, 4950);
    await shopperPage.reload();
    await expect(card(shopperPage)).toContainText('up to $20 here');

    await applyPoints(shopperPage, 3000);

    await expect(card(shopperPage)).toContainText('2,000 Points applied — $20 off');
    await expect(card(shopperPage)).toContainText('3,000 Points remaining');
  });
});

test('checkout stops when the balance no longer covers the applied points', async ({ api, customer, shopperPage }) => {
  await api.adjustPoints(customer.id, 1000);
  await addWidgetToCart(shopperPage);
  await shopperPage.goto('/checkout/');
  await applyPoints(shopperPage, 500);
  await expect(card(shopperPage)).toContainText('500 Points applied — $5 off');

  // Leaves 50, below the minimum, while the checkout page stays open.
  await api.adjustPoints(customer.id, -950);
  await shopperPage.getByLabel('Check payments').check();
  await shopperPage.getByRole('button', { name: 'Place Order' }).click();

  await expect(
    shopperPage.getByRole('listitem').filter({ hasText: 'You do not have enough Points for this discount.' }),
  ).toBeVisible();
  expect(await redeemRows(api, customer.id)).toEqual([]);
  expect(await api.get(`wc/v3/orders?customer=${customer.id}`)).toEqual([]);
  await api.expectPoints(customer.id, { balance: 50 });
});

test.describe('redemption gates', () => {
  let saved: object;

  test.beforeEach(async ({ api, customer, shopperPage }) => {
    saved = await api.settings();
    await api.adjustPoints(customer.id, 1000);
    await addWidgetToCart(shopperPage);
    await shopperPage.goto('/cart/');
    await expect(card(shopperPage).getByRole('button', { name: 'Apply' })).toBeVisible();
  });

  test.afterEach(async ({ api }) => {
    await api.saveSettings(saved);
  });

  /** Reloads the cart and checks it drew without the card. */
  async function expectNoCard(page: Page): Promise<void> {
    await page.reload();
    await expect(page.getByRole('link', { name: 'Proceed to Checkout' })).toBeVisible();
    await expect(card(page)).toHaveCount(0);
  }

  test('a paused program shows no card', async ({ api, shopperPage }) => {
    await api.saveSettings({ ...saved, enabled: false });
    await expectNoCard(shopperPage);
  });

  test('an excluded role shows no card', async ({ api, customer, shopperPage }) => {
    await api.saveSettings({ ...saved, excluded_roles: ['e2e_excluded'] });
    await api.post(`wp/v2/users/${customer.id}`, { roles: ['e2e_excluded'] });
    await expectNoCard(shopperPage);
  });
});
