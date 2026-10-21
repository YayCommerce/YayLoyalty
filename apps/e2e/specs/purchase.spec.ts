import { setOrderStatusInAdmin } from '../support/admin';
import { test, expect } from '../support/fixtures';
import { placedOrderId } from '../support/storefront';

test('a purchase stages pending points at checkout and awards them when the admin completes the order', async ({
  page,
  api,
  customer,
  shopperPage,
  adminPage,
}) => {
  await test.step('a guest sees what the product earns', async () => {
    await page.goto('/product/e2e-widget/');
    await expect(page.getByText('Earn 20 Points with this purchase')).toBeVisible();
  });

  let orderId = 0;

  await test.step('the shopper checks out with Check payments and sees the pending points', async () => {
    await shopperPage.goto('/product/e2e-widget/');
    await shopperPage.getByRole('button', { name: 'Add to cart', exact: true }).click();
    await shopperPage.goto('/checkout/');
    await shopperPage.getByLabel('Check payments').check();
    await shopperPage.getByRole('button', { name: 'Place Order' }).click();
    orderId = await placedOrderId(shopperPage);

    await expect(shopperPage.getByText('+20 Points from this order')).toBeVisible();
    await api.expectPoints(customer.id, { balance: 0, pending_points: 20 });
  });

  await test.step('the admin completes the order', async () => {
    await setOrderStatusInAdmin(adminPage, orderId, 'completed');
    await expect(adminPage.getByText('Earned 20 points', { exact: false })).toBeVisible();

    await api.expectPoints(customer.id, { balance: 20, pending_points: 0, lifetime_earned: 20 });
    expect(await api.ledger(customer.id)).toMatchObject([{ points: 20, type: 'earn' }]);
  });

  await test.step('the shopper sees the balance in My Account and the launcher', async () => {
    await shopperPage.goto('/my-account/rewards/');
    await expect(shopperPage.locator('.yayloyalty-account__balance')).toHaveText('20');
    await expect(shopperPage.getByText(`Order #${orderId}`)).toBeVisible();

    await shopperPage.goto('/shop/');
    await shopperPage.getByRole('button', { name: /20 Points/ }).click();
    await expect(shopperPage.getByRole('dialog').getByText('20', { exact: true })).toBeVisible();
  });
});
