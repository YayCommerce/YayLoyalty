import type { Page } from '@playwright/test';

import { test, expect } from '../support/fixtures';

const MY_POINTS = '/my-account/my-points/';

/** Types the order number into the My Points claim form and submits it; the page reloads with WooCommerce's notice. */
async function claim(page: Page, orderNumber: string): Promise<void> {
  await page.goto(MY_POINTS);
  await page.getByRole('textbox', { name: 'Order number' }).fill(orderNumber);
  await page.getByRole('button', { name: 'Claim points' }).click();
  await page.waitForURL(`**${MY_POINTS}`);
}

test('a shopper claims a guest order billed to their email from My Points, once', async ({
  api,
  customer,
  shopperPage,
}) => {
  // One E2E Widget: $20, so 20 Points under the seeded purchase campaign.
  const orderId = await api.createOrder(0, [{ sku: 'e2e-widget' }], 'completed', customer.email);

  await test.step('a guest order earned nothing', async () => {
    await api.expectPoints(customer.id, { balance: 0 });
  });

  await test.step('the shopper claims it and earns its points', async () => {
    await claim(shopperPage, `#${orderId}`);

    await expect(shopperPage.getByText(`Order #${orderId} is now in your account. You earned 20 Points.`)).toBeVisible();
    // The launcher panel prints the balance too, so read the tab's own card.
    await expect(shopperPage.locator('.woocommerce-MyAccount-content .yayloyalty-account__balance')).toHaveText('20');

    await api.expectPoints(customer.id, { balance: 20, lifetime_earned: 20 });
    expect(await api.ledger(customer.id)).toMatchObject([{ points: 20, type: 'earn' }]);
  });

  await test.step('the order now belongs to the shopper', async () => {
    const order = await api.get<{ customer_id: number }>(`wc/v3/orders/${orderId}`);
    expect(order.customer_id).toBe(customer.id);
  });

  await test.step('claiming it again earns nothing more', async () => {
    await claim(shopperPage, String(orderId));

    await expect(shopperPage.getByText('This order is already in your account.')).toBeVisible();
    expect(await api.ledgerPoints(customer.id)).toEqual([20]);
  });
});

test('a guest order billed to another email cannot be claimed', async ({ api, customer, shopperPage }) => {
  const orderId = await api.createOrder(0, [{ sku: 'e2e-widget' }], 'completed', 'someone-else@example.com');

  await claim(shopperPage, String(orderId));

  await expect(
    shopperPage.getByText('We could not find a guest order with that number for your email address.'),
  ).toBeVisible();
  expect(await api.ledgerPoints(customer.id)).toEqual([]);

  const order = await api.get<{ customer_id: number }>(`wc/v3/orders/${orderId}`);
  expect(order.customer_id).toBe(0);
});

test('an on-hold guest order shows as pending once claimed, and earns when completed', async ({
  api,
  customer,
  shopperPage,
}) => {
  const orderId = await api.createOrder(0, [{ sku: 'e2e-widget' }], 'on-hold', customer.email);

  await claim(shopperPage, String(orderId));

  await expect(
    shopperPage.getByText(`Order #${orderId} is now in your account. 20 Points will be added when it is completed.`),
  ).toBeVisible();
  await expect(
    shopperPage.locator('.woocommerce-MyAccount-content .yayloyalty-account__pending'),
  ).toHaveText('+20 Points pending, added when your orders are completed.');
  await api.expectPoints(customer.id, { balance: 0, pending_points: 20 });

  await api.setOrderStatus(orderId, 'completed');

  await api.expectPoints(customer.id, { balance: 20, pending_points: 0 });
});
