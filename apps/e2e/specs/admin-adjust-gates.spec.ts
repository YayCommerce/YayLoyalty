import type { Api } from '../support/api';
import { test, expect } from '../support/fixtures';
import { wpCli } from '../support/wp-cli';

test('the admin adjusts a balance from the customer drawer', async ({ api, customer, shopperPage, adminPage }) => {
  await adminPage.goto(`/wp-admin/admin.php?page=yayloyalty#/customers/${customer.id}`);
  const drawer = adminPage.getByRole('dialog');

  await test.step('adding points moves the balance and lifetime totals', async () => {
    await drawer.getByRole('button', { name: 'Adjust points' }).click();
    const dialog = adminPage.getByRole('dialog', { name: 'Adjust points' });
    await dialog.getByRole('textbox', { name: 'Amount' }).fill('25');
    await dialog.getByLabel('Reason (internal)').fill('E2E goodwill');
    await dialog.getByRole('switch', { name: 'Custom description for the customer' }).click();
    await dialog.getByPlaceholder('Adjustment', { exact: true }).fill('Thanks for testing');
    await dialog.getByRole('button', { name: 'Add 25 points' }).click();

    await api.expectPoints(customer.id, { balance: 25, lifetime_earned: 25 });
    expect(await api.ledger(customer.id)).toMatchObject([{ points: 25, type: 'adjust' }]);

    await drawer.getByRole('tab', { name: 'Points ledger' }).click();
    await expect(drawer.getByText('E2E goodwill')).toBeVisible();
  });

  await test.step('the shopper sees the description, not the internal reason', async () => {
    await shopperPage.goto('/my-account/rewards/');
    await expect(shopperPage.getByText('Thanks for testing')).toBeVisible();
    await expect(shopperPage.getByText('E2E goodwill')).toHaveCount(0);
  });

  await test.step('a balance-only removal leaves lifetime totals alone', async () => {
    await drawer.getByRole('button', { name: 'Adjust points' }).click();
    const dialog = adminPage.getByRole('dialog', { name: 'Adjust points' });
    await dialog.getByRole('radio', { name: 'Remove' }).click();
    await dialog.getByRole('textbox', { name: 'Amount' }).fill('10');
    await dialog.getByLabel('Reason (internal)').fill('E2E correction');
    await dialog.getByRole('checkbox', { name: 'Adjust lifetime totals too' }).uncheck();
    await dialog.getByRole('button', { name: 'Remove 10 points' }).click();

    await api.expectPoints(customer.id, { balance: 15, lifetime_earned: 25 });
    expect(await api.ledgerPoints(customer.id)).toEqual([25, -10]);
  });
});

test.describe('earning gates', () => {
  let saved: object;

  test.beforeEach(async ({ api }) => {
    saved = await api.settings();
  });

  test.afterEach(async ({ api }) => {
    await api.saveSettings(saved);
  });

  /** Completes an order for the customer and checks it earned nothing. */
  async function expectNoEarn(api: Api, customerId: number): Promise<void> {
    const orderId = await api.createOrder(customerId, [{ sku: 'e2e-widget' }], 'completed');
    const notes = await api.get<Array<{ note: string }>>(`wc/v3/orders/${orderId}/notes`);

    expect(notes.map((note) => note.note).join('\n')).toContain('No points earned');
    expect(await api.ledger(customerId)).toEqual([]);
  }

  test('a paused program awards nothing', async ({ api, customer }) => {
    await api.saveSettings({ ...saved, enabled: false });
    await expectNoEarn(api, customer.id);
  });

  test('an excluded role awards nothing', async ({ api, customer }) => {
    await api.saveSettings({ ...saved, excluded_roles: ['e2e_excluded'] });
    await api.post(`wp/v2/users/${customer.id}`, { roles: ['e2e_excluded'] });
    await expectNoEarn(api, customer.id);
  });

  test('an opted-out customer awards nothing', async ({ api, customer }) => {
    wpCli('user', 'meta', 'update', String(customer.id), '_yayloyalty_opted_out', '1');
    await expectNoEarn(api, customer.id);
  });
});
