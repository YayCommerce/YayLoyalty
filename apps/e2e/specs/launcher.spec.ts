import type { Locator, Page } from '@playwright/test';

import { test, expect } from '../support/fixtures';
import { openLauncher } from '../support/storefront';
import { wpCli } from '../support/wp-cli';

/** A panel section, found by its heading. */
function section(panel: Locator, heading: string): Locator {
  return panel.locator('section', { has: panel.page().getByRole('heading', { name: heading, exact: true }) });
}

/** A "Ways to earn" or "Ways to redeem" row, found by its name. */
function row(panel: Locator, name: string): Locator {
  return panel.locator('.yayloyalty-account__item', { hasText: name });
}

async function openOnShop(page: Page): Promise<Locator> {
  await page.goto('/shop/');

  return openLauncher(page);
}

test.describe('storefront launcher', () => {
  test('a member sees balance, worth and checkout link', async ({ api, customer, shopperPage }) => {
    await api.adjustPoints(customer.id, 1310);
    const panel = await openOnShop(shopperPage);

    await expect(panel.getByText('1,310', { exact: true })).toBeVisible();
    await expect(panel.getByText(/^Worth \$13(\.00)? off your next order$/)).toBeVisible();
    await expect(panel.getByRole('link', { name: 'Use points at checkout' })).toHaveAttribute('href', /\/checkout\/$/);

    const earn = section(panel, 'Ways to earn');
    await expect(row(earn, 'Make a purchase')).toContainText('1 Point / $1');
    await expect(row(earn, 'Write a review')).toContainText('+50 Points');

    await expect(row(section(panel, 'Ways to redeem'), 'Points discount')).toContainText('100 Points = $1');
  });

  test('points expiring within 30 days show a notice', async ({ api, customer, shopperPage }) => {
    await api.adjustPoints(customer.id, 200);
    wpCli(
      'eval',
      `global $wpdb; $wpdb->update( $wpdb->prefix . 'yayloyalty_customers', [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) ], [ 'user_id' => ${customer.id} ] );`,
    );

    await shopperPage.goto('/shop/');
    await expect(shopperPage.locator('[data-yl-open] .yl-launcher-dot')).toBeVisible();

    const panel = await openLauncher(shopperPage);
    await expect(panel.getByText(/200 Points expire on/)).toBeVisible();
    await expect(panel.getByRole('link', { name: 'Use points', exact: true })).toHaveAttribute('href', /\/checkout\/$/);
  });

  test('new points since the last open show once', async ({ api, customer, shopperPage }) => {
    await api.adjustPoints(customer.id, 1310);
    wpCli('eval', `YayLoyalty\\Engine\\Storefront\\NewPointsNotice::mark_seen( ${customer.id} );`);
    // The stamp has whole seconds and excludes rows at its own second.
    await shopperPage.waitForTimeout(1100);
    await api.adjustPoints(customer.id, 40);

    await shopperPage.goto('/shop/');
    const seen = shopperPage.waitForResponse((response) => response.url().includes('activity/seen') && response.ok());
    const panel = await openLauncher(shopperPage);
    await expect(panel.getByText('You earned 40 new Points.')).toBeVisible();
    await seen;

    const reopened = await openOnShop(shopperPage);
    await expect(reopened.getByText('1,350', { exact: true })).toBeVisible();
    await expect(reopened.getByText(/You earned \d+ new/)).toHaveCount(0);
  });

  test('the birthday row links until a birthday is saved', async ({ api, customer, shopperPage }) => {
    const { id } = await api.post<{ id: number }>('yayloyalty/v1/campaigns', {
      name: 'E2E birthday',
      trigger: 'birthday',
      status: 'active',
      config: { points: 100, days_before: 0 },
    });

    try {
      let birthday = row(section(await openOnShop(shopperPage), 'Ways to earn'), 'Celebrate your birthday');
      await expect(birthday).toContainText('+100 Points');
      await expect(birthday.getByRole('link', { name: 'Celebrate your birthday' })).toHaveAttribute(
        'href',
        /edit-account/,
      );

      wpCli('eval', `( new YayLoyalty\\Repositories\\CustomerRepository() )->set_birthday( ${customer.id}, 6, 15 );`);

      birthday = row(section(await openOnShop(shopperPage), 'Ways to earn'), 'Celebrate your birthday');
      await expect(birthday).toContainText('+100 Points');
      await expect(birthday.getByRole('link')).toHaveCount(0);
    } finally {
      await api.delete(`yayloyalty/v1/campaigns/${id}`);
    }
  });

  test('reward catalog lists fixed and percent offers', async ({ api, shopperPage }) => {
    const { panel: before } = await api.get<{ panel: { show_reward_catalog: boolean } }>('yayloyalty/v1/design');
    const fixed = await api.createReward({
      name: 'Launcher $5 off',
      type: 'fixed_coupon',
      cost_points: 300,
      config: { amount: 5 },
    });
    const percent = await api.createReward({
      name: 'Launcher 10% off',
      type: 'percent_coupon',
      cost_points: 500,
      config: { percent: 10, max_discount: 3 },
    });
    await api.post('yayloyalty/v1/design', { panel: { show_reward_catalog: true } });

    try {
      const redeem = section(await openOnShop(shopperPage), 'Ways to redeem');
      await expect(row(redeem, 'Launcher $5 off')).toContainText('$5 off your order');
      await expect(row(redeem, 'Launcher $5 off')).toContainText('300 Points');
      await expect(row(redeem, 'Launcher 10% off')).toContainText('Up to $3 off your order');
      await expect(row(redeem, 'Launcher 10% off')).toContainText('500 Points');
    } finally {
      await api.post('yayloyalty/v1/design', { panel: { show_reward_catalog: before.show_reward_catalog } });
      await api.deleteReward(fixed);
      await api.deleteReward(percent);
    }
  });

  test('recent activity lists rows, or the empty state at zero', async ({ api, customer, shopperPage }) => {
    let activity = section(await openOnShop(shopperPage), 'Recent activity');
    await expect(
      activity.getByText('No Points activity yet. Make your first purchase to start earning Points.'),
    ).toBeVisible();

    await api.adjustPoints(customer.id, 1310);

    activity = section(await openOnShop(shopperPage), 'Recent activity');
    await expect(activity.getByText('+1,310')).toBeVisible();
    await expect(activity.getByRole('link', { name: 'View all' })).toBeVisible();
  });

  test('a guest on a product page sees earn, how it works, join and sign in', async ({ page }) => {
    await page.goto('/product/e2e-widget/');
    const panel = await openLauncher(page);

    await expect(panel.getByRole('heading', { name: 'Earn with this purchase' })).toBeVisible();
    await expect(panel.getByText('20', { exact: true })).toBeVisible();

    const steps = section(panel, 'How it works');
    await expect(row(steps, 'Join for free')).toContainText('+100 welcome Points');
    await expect(row(steps, 'Earn Points')).toContainText('1 Point / $1');
    await expect(row(steps, 'Write a review')).toContainText('+50 Points');
    await expect(row(steps, 'Redeem & save')).toContainText('100 Points = $1');

    await expect(panel.getByRole('link', { name: 'Join & get 100 Points' })).toBeVisible();
    await expect(panel.getByRole('link', { name: 'Sign in', exact: true })).toBeVisible();
  });
});
