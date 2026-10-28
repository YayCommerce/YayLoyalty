import type { Locator, Page } from '@playwright/test';

import { openOrder, setOrderStatusInAdmin, untilReloaded } from '../support/admin';
import { test, expect } from '../support/fixtures';
import { blockDiscounts, blockTotal, card, placedOrderId, pointsDiscountBox, redeemRows, setPoints } from '../support/storefront';

/** A reward's checkbox, named by its row: "$5 off 500 Points …". */
function rewardBox(page: Page, name: string): Locator {
  return card(page).getByRole('checkbox', { name: new RegExp(`^${name.replace(/\$/g, '\\$')}`) });
}

/** Five E2E Widgets: a $100 cart. */
async function addWidgetsToCart(page: Page): Promise<void> {
  await page.goto('/product/e2e-widget/');
  await page.locator('input.qty').fill('5');
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click();
  await expect(page.getByText('have been added to your cart')).toBeVisible();
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

  test('the points discount and rewards stack on the block checkout and a cancellation gives the points back', async ({
    api,
    customer,
    shopperPage,
    adminPage,
  }) => {
    await api.adjustPoints(customer.id, 9850);
    await addWidgetsToCart(shopperPage);

    await test.step('the cart card lists the points discount and both rewards', async () => {
      await shopperPage.goto('/cart/');
      await expect(card(shopperPage)).toContainText('Your rewards');
      await expect(card(shopperPage)).toContainText('9,850 Points');
      await expect(card(shopperPage)).toContainText('$98');
      await expect(pointsDiscountBox(shopperPage)).not.toBeChecked();
      await expect(rewardBox(shopperPage, '$5 off')).not.toBeChecked();
      await expect(rewardBox(shopperPage, '15% off')).not.toBeChecked();
      await expect(card(shopperPage).locator('label', { hasText: '$5 off' })).toContainText('500 Points');
      await expect(card(shopperPage).locator('label', { hasText: '15% off' })).toContainText('1,500 Points');
      await expect(card(shopperPage).getByRole('tab')).toHaveCount(0);
      await expect(card(shopperPage).getByRole('button', { name: 'Apply' })).toHaveCount(0);
    });

    await test.step('ticks apply at once', async () => {
      await rewardBox(shopperPage, '$5 off').check();
      await expect(blockDiscounts(shopperPage)).toContainText('$5 off');
      await expect(blockDiscounts(shopperPage)).not.toContainText('yayloyalty-reward-');
      await expect(blockTotal(shopperPage)).toContainText('$95.00');

      await pointsDiscountBox(shopperPage).check();
      await expect(blockDiscounts(shopperPage)).toContainText('Points discount');
      await setPoints(shopperPage, 500);
      await expect(blockTotal(shopperPage)).toContainText('$90.00');
    });

    let orderId = 0;

    await test.step('the block checkout collapses to the summary and the order debits both', async () => {
      await shopperPage.goto('/checkout/');
      await expect(card(shopperPage)).toContainText('2 rewards selected');
      await expect(card(shopperPage).getByRole('button', { name: 'Change' })).toHaveAttribute('aria-expanded', 'false');
      await expect(card(shopperPage).locator('[data-yl-list]')).toBeHidden();

      await shopperPage.getByLabel('Check payments').check();
      await shopperPage.getByRole('button', { name: 'Place Order' }).click();
      orderId = await placedOrderId(shopperPage);

      await api.expectPoints(customer.id, { balance: 8850 });
      expect(await redeemRows(api, customer.id)).toEqual([-500, -500]);
      expect(await api.redemptions(customer.id)).toMatchObject([
        { points: -500, value: '5.0000', status: 'applied' },
        { points: -500, value: '5.0000', status: 'applied' },
      ]);
      expect(await api.get(`wc/v3/orders/${orderId}`)).toMatchObject({ discount_total: '10.00', total: '90.00' });
    });

    await test.step("the drawer's ledger shows both debits", async () => {
      await adminPage.goto(`/wp-admin/admin.php?page=yayloyalty#/customers/${customer.id}`);
      const drawer = adminPage.getByRole('dialog');
      await drawer.getByRole('tab', { name: 'Points ledger' }).click();

      await expect(drawer.getByRole('row').filter({ hasText: '-500' })).toHaveCount(2);
    });

    await test.step('cancelling the order restores both', async () => {
      await setOrderStatusInAdmin(adminPage, orderId, 'cancelled');

      await api.expectPoints(customer.id, { balance: 9850 });
      expect(await api.redemptions(customer.id)).toMatchObject([{ status: 'cancelled' }, { status: 'cancelled' }]);
    });
  });

  test('the points discount and rewards stack on the classic pages, edited once at checkout, and a full refund gives the points back', async ({
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

    await test.step("WooCommerce's own Remove takes the points discount off and leaves the reward", async () => {
      await shopperPage.goto('/e2e-classic-cart/');
      await rewardBox(shopperPage, '$5 off').check();
      await expect(discounts).toHaveCount(1);
      await pointsDiscountBox(shopperPage).check();
      await expect(discounts).toHaveCount(2);
      await setPoints(shopperPage, 500);
      await expect(discounts.filter({ hasText: 'Points discount' })).toContainText('$5.00');

      await discounts.filter({ hasText: 'Points discount' }).getByRole('button', { name: /Remove/ }).click();
      await expect(discounts).toHaveCount(1);
      await expect(discounts).toContainText('$5 off');
      await expect(pointsDiscountBox(shopperPage)).not.toBeChecked();

      await pointsDiscountBox(shopperPage).check();
      await expect(discounts).toHaveCount(2);
      await setPoints(shopperPage, 500);
      await expect(discounts.filter({ hasText: 'Points discount' })).toContainText('$5.00');
    });

    let orderId = 0;
    const reviewDiscounts = shopperPage.locator('#order_review tr.cart-discount');

    await test.step('Change, a tick and Update edit the selection at checkout, and the order debits all three', async () => {
      // The checkout redraws the order review, card included, once on load.
      const reviewed = shopperPage.waitForResponse(/wc-ajax=update_order_review/);
      await shopperPage.goto('/e2e-classic-checkout/');
      await reviewed;
      await expect(shopperPage.locator('.blockUI')).toHaveCount(0);
      await expect(card(shopperPage)).toContainText('2 rewards selected');

      await card(shopperPage).getByRole('button', { name: 'Change' }).click();
      await expect(card(shopperPage).getByRole('button', { name: 'Update' })).toBeVisible();
      await rewardBox(shopperPage, '15% off').check();
      await expect(reviewDiscounts).toHaveCount(2);

      await card(shopperPage).getByRole('button', { name: 'Update' }).click();
      await expect(reviewDiscounts).toHaveCount(3);
      await expect(card(shopperPage)).toContainText('3 rewards selected');
      await expect(shopperPage.locator('.blockUI')).toHaveCount(0);

      await shopperPage.locator('#payment_method_cheque').check({ force: true });
      await shopperPage.getByRole('button', { name: 'Place order' }).click();
      orderId = await placedOrderId(shopperPage);

      await api.expectPoints(customer.id, { balance: 7350 });
      const rows = await api.redemptions(customer.id);
      expect(rows).toHaveLength(3);
      expect(rows).toEqual(
        expect.arrayContaining([
          expect.objectContaining({ points: -500, value: '5.0000', status: 'applied' }),
          expect.objectContaining({ points: -1500, value: '10.0000', status: 'applied' }),
        ]),
      );
    });

    await test.step('the admin refunds the whole order and all three come back', async () => {
      await openOrder(adminPage, orderId);
      await adminPage.getByRole('button', { name: 'Refund', exact: true }).click();
      const widget = adminPage.locator('#order_line_items tr.item', { hasText: 'E2E Widget' });
      await widget.locator('input.refund_order_item_qty').fill('5');
      await widget.locator('input.refund_order_item_qty').dispatchEvent('change');
      await untilReloaded(adminPage, () => adminPage.getByRole('button', { name: /Refund \$80\.00 manually/ }).click());

      await api.expectPoints(customer.id, { balance: 9850 });
      expect(await api.redemptions(customer.id)).toMatchObject([
        { status: 'refunded' },
        { status: 'refunded' },
        { status: 'refunded' },
      ]);
    });
  });

  test('the points discount can use up to what the rewards leave, on the increment', async ({ api, customer, shopperPage }) => {
    await api.adjustPoints(customer.id, 9850);
    await addWidgetsToCart(shopperPage);
    await shopperPage.goto('/cart/');

    await rewardBox(shopperPage, '$5 off').check();
    await expect(blockDiscounts(shopperPage)).toContainText('$5 off');
    await rewardBox(shopperPage, '15% off').check();
    await expect(blockDiscounts(shopperPage)).toContainText('15% off');
    await pointsDiscountBox(shopperPage).check();

    await expect(blockDiscounts(shopperPage)).toContainText('Points discount');
    await expect(card(shopperPage)).toContainText('Use up to 7,800 Points (= $78)');
  });

  test('rewards beyond the balance cannot be ticked', async ({ api, customer, shopperPage }) => {
    await api.adjustPoints(customer.id, 1800);
    await addWidgetsToCart(shopperPage);
    await shopperPage.goto('/cart/');

    await expect(rewardBox(shopperPage, '15% off')).toBeEnabled();
    await rewardBox(shopperPage, '$5 off').check();

    await expect(rewardBox(shopperPage, '15% off')).toBeDisabled();
  });
});
