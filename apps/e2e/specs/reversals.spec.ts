import { openOrder, setOrderStatusInAdmin, untilReloaded } from '../support/admin';
import { test, expect } from '../support/fixtures';

test('refunds, refund deletes and cancellations move the points the order earned', async ({
  api,
  customer,
  shopperPage,
  adminPage,
}) => {
  // WooCommerce confirms refunds and refund deletes with window.confirm().
  adminPage.on('dialog', (dialog) => dialog.accept());

  const orderId = await api.createOrder(customer.id, [{ sku: 'e2e-widget' }, { sku: 'e2e-gadget' }], 'completed');
  await api.expectPoints(customer.id, { balance: 30 });

  await test.step('the admin refunds the 10.00 line and its 10 points come back off', async () => {
    await openOrder(adminPage, orderId);
    await adminPage.getByRole('button', { name: 'Refund', exact: true }).click();
    const gadget = adminPage.locator('#order_line_items tr.item', { hasText: 'E2E Gadget' });
    await gadget.locator('input.refund_order_item_qty').fill('1');
    await gadget.locator('input.refund_order_item_qty').dispatchEvent('change');
    await untilReloaded(adminPage, () => adminPage.getByRole('button', { name: /Refund \$10\.00 manually/ }).click());

    await api.expectPoints(customer.id, { balance: 20 });
    expect(await api.ledger(customer.id)).toMatchObject([{ points: 30 }, { points: -10, type: 'refund_earn' }]);
  });

  await test.step('the admin deletes the refund and the points are restored', async () => {
    await openOrder(adminPage, orderId);
    await adminPage.locator('#order_refunds a.delete_refund').click({ force: true });

    await api.expectPoints(customer.id, { balance: 30 });
  });

  await test.step('cancelling an on-hold order releases its pending points', async () => {
    const onHoldId = await api.createOrder(customer.id, [{ sku: 'e2e-widget' }], 'on-hold');
    await api.expectPoints(customer.id, { balance: 30, pending_points: 20 });

    await setOrderStatusInAdmin(adminPage, onHoldId, 'cancelled');

    await api.expectPoints(customer.id, { balance: 30, pending_points: 0 });
  });

  await test.step('cancelling the completed order takes back everything it earned', async () => {
    await setOrderStatusInAdmin(adminPage, orderId, 'cancelled');

    await api.expectPoints(customer.id, { balance: 0 });
    expect(await api.ledgerPoints(customer.id)).toEqual([30, -10, 10, -30]);
  });

  await test.step('the shopper sees each reversal in their history', async () => {
    await shopperPage.goto('/my-account/rewards/');
    await expect(shopperPage.getByText('Points removed — order refunded')).toHaveCount(2);
    // A deleted refund's restore is a system adjustment, labelled with the store name.
    await expect(shopperPage.getByText(/Adjustment by /)).toHaveCount(1);
  });
});
