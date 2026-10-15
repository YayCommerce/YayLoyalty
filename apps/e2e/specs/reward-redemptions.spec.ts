import type { Locator, Page } from '@playwright/test';

import { openOrder, setOrderStatusInAdmin, untilReloaded } from '../support/admin';
import { test, expect } from '../support/fixtures';
import { card, placedOrderId, redeemRows } from '../support/storefront';

/** A reward's checkbox, named by its row: "$5 off 500 Points …". */
function rewardBox(page: Page, name: string): Locator {
  return card(page).getByRole('checkbox', { name: new RegExp(`^${name.replace(/\$/g, '\\$')}`) });
}

/** The block totals' discount rows. */
function blockDiscounts(page: Page): Locator {
  return page.locator('.wc-block-components-totals-discount');
}

/** Five E2E Widgets: a $100 cart. */
async function addWidgetsToCart(page: Page): Promise<void> {
  await page.goto('/product/e2e-widget/');
  await page.locator('input.qty').fill('5');
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click();
  await expect(page.getByText('have been added to your cart')).toBeVisible();
}

async function applyRewards(page: Page, names: string[], button: 'Apply' | 'Update' = 'Apply'): Promise<void> {
  for (const name of names) {
    await rewardBox(page, name).check();
  }
  await card(page).getByRole('button', { name: button }).click();
}

test.describe('reward redemptions', () => {
  let rewardA = 0;
  let rewardB = 0;

  test.beforeEach(async ({ api }) => {
    rewardA = await api.createReward({
      name: '$5 off',
      type: 'fixed_coupon',
      cost_points: 500,
      config: { amount: 5, min_order: 25 },
    });
    rewardB = await api.createReward({
      name: '15% off',
      type: 'percent_coupon',
      cost_points: 1500,
      config: { percent: 15, max_discount: 10 },
    });
  });

  test.afterEach(async ({ api }) => {
    await api.deleteReward(rewardA);
    await api.deleteReward(rewardB);
  });

  test('rewards apply together on the block checkout and a cancellation gives the points back', async ({
    api,
    customer,
    shopperPage,
    adminPage,
  }) => {
    await api.adjustPoints(customer.id, 9850);
    await addWidgetsToCart(shopperPage);

    await test.step('the cart card lists both rewards and applies them together', async () => {
      await shopperPage.goto('/cart/');
      await expect(card(shopperPage)).toContainText('Your rewards');
      await expect(card(shopperPage)).toContainText('9,850 Points');
      await expect(card(shopperPage).getByRole('tab', { name: 'Rewards' })).toHaveAttribute('aria-selected', 'true');
      await expect(card(shopperPage).getByRole('tab', { name: 'Points discount' })).toBeVisible();
      await expect(rewardBox(shopperPage, '$5 off')).toBeVisible();
      await expect(card(shopperPage).locator('label', { hasText: '$5 off' })).toContainText('500 Points');
      await expect(card(shopperPage).locator('label', { hasText: '15% off' })).toContainText('1,500 Points');

      await rewardBox(shopperPage, '$5 off').check();
      await rewardBox(shopperPage, '15% off').check();
      await expect(card(shopperPage)).toContainText('2 selected · 2,000 Points');
      await card(shopperPage).getByRole('button', { name: 'Apply' }).click();

      await expect(card(shopperPage)).toContainText('2,000 Points will be used · 7,850 remaining');
      await expect(blockDiscounts(shopperPage)).toContainText('$5 off');
      await expect(blockDiscounts(shopperPage)).toContainText('15% off');
      await expect(blockDiscounts(shopperPage)).not.toContainText('yayloyalty-reward-');
      await expect(shopperPage.locator('.wc-block-components-totals-footer-item')).toContainText('$85.00');
    });

    let orderId = 0;

    await test.step('the block checkout order debits each reward', async () => {
      await shopperPage.goto('/checkout/');
      await shopperPage.getByLabel('Check payments').check();
      await shopperPage.getByRole('button', { name: 'Place Order' }).click();
      orderId = await placedOrderId(shopperPage);

      await api.expectPoints(customer.id, { balance: 7850 });
      // Oldest first, in the order's coupon-line order: percent rewards before fixed ones.
      expect(await redeemRows(api, customer.id)).toEqual([-1500, -500]);
      expect(await api.redemptions(customer.id)).toMatchObject([
        { points: -500, value: '5.0000', status: 'applied' },
        { points: -1500, value: '10.0000', status: 'applied' },
      ]);
      expect(await api.get(`wc/v3/orders/${orderId}`)).toMatchObject({ discount_total: '15.00', total: '85.00' });
    });

    await test.step('cancelling the order restores both rewards', async () => {
      await setOrderStatusInAdmin(adminPage, orderId, 'cancelled');

      await api.expectPoints(customer.id, { balance: 9850 });
      expect(await api.redemptions(customer.id)).toMatchObject([{ status: 'cancelled' }, { status: 'cancelled' }]);
    });
  });

  test('rewards apply on the classic pages and a full refund gives the points back', async ({
    api,
    customer,
    shopperPage,
    adminPage,
  }) => {
    // WooCommerce confirms refunds with window.confirm().
    adminPage.on('dialog', (dialog) => dialog.accept());

    await api.adjustPoints(customer.id, 9850);
    await addWidgetsToCart(shopperPage);

    const discounts = shopperPage.locator('.cart_totals tr.cart-discount');

    await test.step("WooCommerce's own Remove takes one reward off and Update puts it back", async () => {
      await shopperPage.goto('/e2e-classic-cart/');
      await applyRewards(shopperPage, ['$5 off', '15% off']);
      await expect(card(shopperPage)).toContainText('2,000 Points will be used · 7,850 remaining');
      await expect(discounts).toHaveCount(2);

      await discounts.filter({ hasText: '$5 off' }).getByRole('button', { name: /Remove/ }).click();
      await expect(discounts).toHaveCount(1);
      await expect(discounts).toContainText('15% off');
      await expect(card(shopperPage)).toContainText('1,500 Points will be used · 8,350 remaining');

      await applyRewards(shopperPage, ['$5 off'], 'Update');
      await expect(card(shopperPage)).toContainText('2,000 Points will be used · 7,850 remaining');
      await expect(discounts).toHaveCount(2);
    });

    let orderId = 0;

    await test.step('the classic checkout order debits each reward', async () => {
      await shopperPage.goto('/e2e-classic-checkout/');
      await expect(shopperPage.locator('#order_review tr.cart-discount')).toHaveCount(2);
      await expect(shopperPage.locator('.blockUI')).toHaveCount(0);
      await shopperPage.locator('#payment_method_cheque').check({ force: true });
      await shopperPage.getByRole('button', { name: 'Place order' }).click();
      orderId = await placedOrderId(shopperPage);

      await api.expectPoints(customer.id, { balance: 7850 });
    });

    await test.step('the admin refunds the whole order and both rewards come back', async () => {
      await openOrder(adminPage, orderId);
      await adminPage.getByRole('button', { name: 'Refund', exact: true }).click();
      const widget = adminPage.locator('#order_line_items tr.item', { hasText: 'E2E Widget' });
      await widget.locator('input.refund_order_item_qty').fill('5');
      await widget.locator('input.refund_order_item_qty').dispatchEvent('change');
      await untilReloaded(adminPage, () => adminPage.getByRole('button', { name: /Refund \$85\.00 manually/ }).click());

      await api.expectPoints(customer.id, { balance: 9850 });
      expect(await api.redemptions(customer.id)).toMatchObject([{ status: 'refunded' }, { status: 'refunded' }]);
    });
  });

  test('the points discount and rewards exclude each other', async ({ api, customer, shopperPage }) => {
    await api.adjustPoints(customer.id, 9850);
    await addWidgetsToCart(shopperPage);
    await shopperPage.goto('/cart/');

    await test.step('applied rewards close the points discount', async () => {
      await applyRewards(shopperPage, ['$5 off']);
      await expect(card(shopperPage)).toContainText('500 Points will be used · 9,350 remaining');

      await card(shopperPage).getByRole('tab', { name: 'Points discount' }).click();
      await expect(card(shopperPage)).toContainText('Remove your rewards to use a Points discount.');
      await expect(card(shopperPage).getByRole('slider')).toHaveCount(0);
    });

    await test.step('an applied points discount closes the rewards', async () => {
      await card(shopperPage).getByRole('tab', { name: 'Rewards' }).click();
      await rewardBox(shopperPage, '$5 off').uncheck();
      await card(shopperPage).getByRole('button', { name: 'Update' }).click();
      await expect(blockDiscounts(shopperPage)).toHaveCount(0);

      await card(shopperPage).getByRole('tab', { name: 'Points discount' }).click();
      await card(shopperPage).getByRole('spinbutton', { name: 'Points to spend' }).fill('500');
      await card(shopperPage).getByRole('button', { name: 'Apply' }).click();
      await expect(card(shopperPage)).toContainText('500 Points applied — $5 off');
      await expect(blockDiscounts(shopperPage)).toContainText('Points discount');
      await expect(blockDiscounts(shopperPage)).not.toContainText('$5 off');

      await card(shopperPage).getByRole('tab', { name: 'Rewards' }).click();
      await expect(card(shopperPage)).toContainText('Remove your Points discount to use rewards.');
      await expect(rewardBox(shopperPage, '$5 off')).toBeDisabled();
      await expect(rewardBox(shopperPage, '15% off')).toBeDisabled();
    });
  });

  test('rewards beyond the balance cannot be ticked', async ({ api, customer, shopperPage }) => {
    await api.adjustPoints(customer.id, 1800);
    await addWidgetsToCart(shopperPage);
    await shopperPage.goto('/cart/');

    await expect(rewardBox(shopperPage, '15% off')).toBeEnabled();
    await rewardBox(shopperPage, '$5 off').check();

    await expect(rewardBox(shopperPage, '15% off')).toBeDisabled();
    await expect(card(shopperPage).locator('label', { hasText: '15% off' })).toContainText('Not enough Points');
  });
});
