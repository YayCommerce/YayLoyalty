import { openOrder } from '../support/admin';
import { test, expect } from '../support/fixtures';
import { addWidgetsToCart, blockDiscounts, placedOrderId, rewardBox } from '../support/storefront';

test.describe('an admin removes a loyalty coupon', () => {
  let rewardId = 0;

  test.beforeEach(async ({ api }) => {
    rewardId = await api.createReward({ name: '$5 off', type: 'fixed_coupon', cost_points: 500, config: { amount: 5 } });
  });

  test.afterEach(async ({ api }) => {
    await api.deleteReward(rewardId);
  });

  test('Restore takes the line off and returns its points', async ({ api, customer, shopperPage, adminPage }) => {
    await api.adjustPoints(customer.id, 1000);
    await addWidgetsToCart(shopperPage);
    await shopperPage.goto('/cart/');
    await rewardBox(shopperPage, '$5 off').check();
    await expect(blockDiscounts(shopperPage)).toContainText('$5 off');
    await shopperPage.goto('/checkout/');
    await shopperPage.getByLabel('Check payments').check();
    await shopperPage.getByRole('button', { name: 'Place Order' }).click();
    const orderId = await placedOrderId(shopperPage);
    await api.expectPoints(customer.id, { balance: 500 });

    await openOrder(adminPage, orderId);
    const removeLink = adminPage.locator('#woocommerce-order-items a.remove-coupon');
    // The link is empty and WooCommerce draws its icon in ::before, so it has no box to click: dispatch the DOM click.
    const clickRemove = () => removeLink.dispatchEvent('click');
    const dialog = adminPage.getByRole('dialog', { name: 'Remove $5 off?' });

    await test.step('Cancel keeps the line', async () => {
      await clickRemove();
      await expect(dialog).toContainText('The customer spent 500 Points on this discount.');
      await expect(dialog.getByRole('button', { name: 'Restore 500 Points' })).toBeFocused();
      await expect(dialog.getByRole('button', { name: "Don't restore Points" })).toBeVisible();
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(dialog).toBeHidden();
      await expect(removeLink).toHaveCount(1);
    });

    await test.step('Restore removes the line and returns 500', async () => {
      await clickRemove();
      await dialog.getByRole('button', { name: 'Restore 500 Points' }).click();
      await expect(removeLink).toHaveCount(0);
      await expect(adminPage.locator('ul.order_notes')).toContainText('$5 off removed — 500 Points restored.');
      await api.expectPoints(customer.id, { balance: 1000 });
      expect(await api.redemptions(customer.id)).toMatchObject([{ status: 'removed' }]);
    });

    await test.step('the Customers page shows the balance and the status', async () => {
      await adminPage.goto(`/wp-admin/admin.php?page=yayloyalty#/customers/${customer.id}`);
      const drawer = adminPage.getByRole('dialog');
      await expect(drawer).toContainText('1,000');
      await drawer.getByRole('tab', { name: 'Redemptions' }).click();
      await expect(drawer).toContainText('Removed by admin');
    });
  });
});
