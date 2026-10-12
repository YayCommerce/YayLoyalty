import { test, expect } from '../support/fixtures';

test('a visitor who registers earns the signup points', async ({ page, api }) => {
  const email = `e2e-signup-${Date.now()}@example.com`;

  await page.goto('/my-account/');
  const form = page.locator('form.register');
  await form.getByLabel(/Email address/).fill(email);
  // Typed, not filled: with fill() the Register click submitted nothing.
  await form.getByLabel(/Password/).pressSequentially(`pw-${Date.now()}-E2e!`);
  await Promise.all([
    page.waitForResponse(
      (response) => response.request().method() === 'POST' && response.url().includes('/my-account/'),
    ),
    form.getByRole('button', { name: 'Register' }).click(),
  ]);

  const [{ id }] = await api.get<Array<{ id: number }>>(`wc/v3/customers?email=${encodeURIComponent(email)}`);

  try {
    await api.expectPoints(id, { balance: 100 });
    expect(await api.ledger(id)).toMatchObject([{ points: 100, type: 'earn' }]);

    await page.goto('/my-account/rewards/');
    await expect(page.getByText('Account created')).toBeVisible();
  } finally {
    await api.deleteCustomer(id);
  }
});

test('an approved verified review earns, and unapproving it takes the points back', async ({
  api,
  customer,
  shopperPage,
  adminPage,
}) => {
  const review = `E2E review ${Date.now()}: works as described.`;

  // A completed purchase makes the review verified.
  await api.createOrder(customer.id, [{ sku: 'e2e-widget' }], 'completed');
  await api.expectPoints(customer.id, { balance: 20 });

  await test.step('the shopper reviews the product and it waits for moderation', async () => {
    await shopperPage.goto('/product/e2e-widget/#reviews');
    await shopperPage.getByRole('tab', { name: /Reviews/ }).click();
    await shopperPage.getByRole('radio', { name: /5 of 5 stars/ }).check();
    await shopperPage.getByLabel(/Your review/).fill(review);
    await shopperPage.getByRole('button', { name: 'Submit' }).click();
    await expect(shopperPage.getByText(/awaiting approval/i)).toBeVisible();

    await api.expectPoints(customer.id, { balance: 20 });
  });

  const row = adminPage.locator('tr', { hasText: review });

  await test.step('the admin approves it and the review points are awarded', async () => {
    await adminPage.goto('/wp-admin/edit.php?post_type=product&page=product-reviews&comment_status=moderated');
    await row.hover();
    await row
      .getByRole('button', { name: 'Approve' })
      .or(row.getByRole('link', { name: 'Approve' }))
      .first()
      .click();

    await api.expectPoints(customer.id, { balance: 70 });
    expect(await api.ledgerPoints(customer.id)).toEqual([20, 50]);
  });

  await test.step('the admin unapproves it and the review points are taken back', async () => {
    await adminPage.goto('/wp-admin/edit.php?post_type=product&page=product-reviews&comment_status=approved');
    await row.hover();
    await row
      .getByRole('button', { name: 'Unapprove' })
      .or(row.getByRole('link', { name: 'Unapprove' }))
      .first()
      .click();

    await api.expectPoints(customer.id, { balance: 20 });
    expect(await api.ledgerPoints(customer.id)).toEqual([20, 50, -50]);
  });
});
