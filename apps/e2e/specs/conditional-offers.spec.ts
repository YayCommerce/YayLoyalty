import type { Browser, Locator, Page } from '@playwright/test';

import { adminApi, type Api } from '../support/api';
import { test, expect, logIn } from '../support/fixtures';
import { card, openLauncher, rewardBox } from '../support/storefront';
import { wpCli } from '../support/wp-cli';

/**
 * Spec criteria 1–6 of the conditional offers design: the store has "Base" (the seeded purchase campaign,
 * 1 point per $1), "VIP" (2 per $1 for the `vip` role) and "Shoes" (3 per $1 when the order includes Shoes),
 * in that priority order from the bottom, and the reward "VIP $10" for the `vip` role.
 */

const PAGE_SLUG = 'e2e-offers';
const SHOES_LINE = '3 Points / $1 when your order includes Shoes';
const VIP_LINE = '2 Points / $1 for VIP members';

type Seed = {
  categoryId: number;
  shoesId: number;
  plainId: number;
  pageId: number;
  vipCampaignId: number;
  shoesCampaignId: number;
  rewardId: number;
};

type Member = Awaited<ReturnType<Api['createCustomer']>>;

const REWARD_CONFIG = { currency: 'USD', amount: 10, min_order: null, exclude_sale_items: false };

let api: Api;
let seed: Seed;
const members: Member[] = [];

/** A placed-shortcode section, found by its heading. */
function section(scope: Page | Locator, heading: string): Locator {
  const page = 'page' in scope ? scope.page() : scope;

  return scope.locator('section', { has: page.getByRole('heading', { name: heading, exact: true }) });
}

function row(scope: Locator, name: string): Locator {
  return scope.locator('.yayloyalty-account__item', { hasText: name });
}

/** The purchase row of "Ways to earn": "Make a purchase" for a member, "Earn Points" for a guest. */
function purchaseRow(scope: Locator): Locator {
  return scope.locator('.yayloyalty-account__item', { hasText: /Make a purchase|Earn Points/ });
}

async function guestPage(browser: Browser): Promise<Page> {
  return (await browser.newContext()).newPage();
}

/** A fresh member with 1,000 points, logged in on its own page, so no cart carries over between tests. */
async function memberPage(browser: Browser, role: 'customer' | 'vip'): Promise<Page> {
  const member = await api.createCustomer(role);
  members.push(member);
  await api.adjustPoints(member.id, 1000);

  const page = await (await browser.newContext()).newPage();
  await logIn(page, member.username, member.password);

  return page;
}

async function addToCart(page: Page, slug: string): Promise<void> {
  await page.goto(`/product/${slug}/`);
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click();
  await expect(page.getByText('has been added to your cart')).toBeVisible();
}

async function setCampaignAdvert(id: number, points: number, advert: object): Promise<void> {
  await api.put(`yayloyalty/v1/campaigns/${id}`, { config: { points_per_unit: points, ...advert } });
}

async function setRewardAdvert(advert: object): Promise<void> {
  await api.put(`yayloyalty/v1/rewards/${seed.rewardId}`, { config: { ...REWARD_CONFIG, ...advert } });
}

test.describe.configure({ mode: 'serial' });

test.describe('conditional offers', () => {
  test.beforeAll(async () => {
    api = await adminApi();

    try {
      wpCli('role', 'exists', 'vip');
    } catch {
      wpCli('role', 'create', 'vip', 'VIP', '--clone=customer');
    }

    const { id: categoryId } = await api.post<{ id: number }>('wc/v3/products/categories', {
      name: 'Shoes',
      slug: `e2e-shoes-${Date.now()}`,
    });
    const product = (sku: string, categories: object[]) =>
      api.post<{ id: number }>('wc/v3/products', {
        name: sku === 'e2e-shoes' ? 'E2E Shoes' : 'E2E Plain',
        sku,
        slug: sku,
        regular_price: '100',
        status: 'publish',
        categories,
      });
    const { id: shoesId } = await product('e2e-shoes', [{ id: categoryId }]);
    const { id: plainId } = await product('e2e-plain', []);
    const { id: pageId } = await api.post<{ id: number }>('wp/v2/pages', {
      title: 'E2E offers',
      slug: PAGE_SLUG,
      status: 'publish',
      content: '<!-- wp:shortcode -->[yayloyalty_ways_to_earn][yayloyalty_ways_to_redeem]<!-- /wp:shortcode -->',
    });

    // Each new campaign goes on top of its group: VIP first, then Shoes above it, both above the seeded base.
    const campaign = (name: string, points: number, conditions: object[]) =>
      api.post<{ id: number }>('yayloyalty/v1/campaigns', {
        name,
        trigger: 'purchase',
        status: 'active',
        config: { points_per_unit: points },
        conditions,
      });
    const { id: vipCampaignId } = await campaign('VIP', 2, [{ type: 'user_role', operator: 'in', value: ['vip'] }]);
    const { id: shoesCampaignId } = await campaign('Shoes', 3, [
      { type: 'category_in_cart', operator: 'in', value: [categoryId] },
    ]);

    const rewardId = await api.createReward({
      name: 'VIP $10',
      type: 'fixed_coupon',
      cost_points: 500,
      config: REWARD_CONFIG,
      conditions: [{ type: 'user_role', operator: 'in', value: ['vip'] }],
    });

    seed = { categoryId, shoesId, plainId, pageId, vipCampaignId, shoesCampaignId, rewardId };
  });

  test.afterAll(async () => {
    for (const member of members) {
      await api.deleteCustomer(member.id);
    }

    if (seed) {
      await api.delete(`yayloyalty/v1/campaigns/${seed.shoesCampaignId}`);
      await api.delete(`yayloyalty/v1/campaigns/${seed.vipCampaignId}`);
      await api.deleteReward(seed.rewardId);
      await api.delete(`wc/v3/products/${seed.shoesId}?force=true`);
      await api.delete(`wc/v3/products/${seed.plainId}?force=true`);
      await api.delete(`wc/v3/products/categories/${seed.categoryId}?force=true`);
      await api.delete(`wp/v2/pages/${seed.pageId}?force=true`);
    }

    wpCli('role', 'delete', 'vip');
    await api.dispose();
  });

  test('1: a guest sees the base rate and both bonus lines, the same for every guest', async ({ browser }) => {
    const first = await guestPage(browser);
    await first.goto(`/${PAGE_SLUG}/`);
    const earn = section(first, 'Ways to earn');

    await expect(purchaseRow(earn)).toContainText('1 Point / $1');
    await expect(purchaseRow(earn).locator('.yayloyalty-account__item-bonus')).toHaveText([SHOES_LINE, VIP_LINE]);

    const second = await guestPage(browser);
    await second.goto(`/${PAGE_SLUG}/`);
    expect(await section(second, 'Ways to earn').innerHTML()).toBe(await earn.innerHTML());

    await first.goto('/shop/');
    const panel = await openLauncher(first);
    await expect(purchaseRow(panel)).toContainText('1 Point / $1');
    await expect(purchaseRow(panel).locator('.yayloyalty-account__item-bonus')).toHaveText([SHOES_LINE, VIP_LINE]);
  });

  test('2: a vip member sees 2 per $1 and the Shoes line; a plain member sees 1 per $1 and both', async ({
    browser,
  }) => {
    const vip = await memberPage(browser, 'vip');
    await vip.goto(`/${PAGE_SLUG}/`);
    const vipRow = purchaseRow(section(vip, 'Ways to earn'));
    await expect(vipRow).toContainText('2 Points / $1');
    await expect(vipRow.locator('.yayloyalty-account__item-bonus')).toHaveText([SHOES_LINE]);

    const plain = await memberPage(browser, 'customer');
    await plain.goto(`/${PAGE_SLUG}/`);
    const plainRow = purchaseRow(section(plain, 'Ways to earn'));
    await expect(plainRow).toContainText('1 Point / $1');
    await expect(plainRow.locator('.yayloyalty-account__item-bonus')).toHaveText([SHOES_LINE, VIP_LINE]);
  });

  test('3: the product line shows the personal figure, and a guest the VIP bonus', async ({ browser }) => {
    const vip = await memberPage(browser, 'vip');

    await vip.goto('/product/e2e-shoes/');
    await expect(vip.locator('.yayloyalty-product-earn__line')).toHaveText('Earn 300 Points with this purchase');
    await expect(vip.locator('.yayloyalty-product-earn__bonus')).toHaveCount(0);

    await vip.goto('/product/e2e-plain/');
    await expect(vip.locator('.yayloyalty-product-earn__line')).toHaveText('Earn 200 Points with this purchase');
    await expect(vip.locator('.yayloyalty-product-earn__bonus')).toHaveCount(0);

    const guest = await guestPage(browser);
    await guest.goto('/product/e2e-plain/');
    await expect(guest.locator('.yayloyalty-product-earn__line')).toHaveText('Earn 100 Points with this purchase');
    await expect(guest.locator('.yayloyalty-product-earn__bonus')).toHaveText(['Earn 200 Points for VIP members']);
    await expect(guest.locator('.yayloyalty-product-earn__guest')).toBeVisible();
  });

  test('4: the Cart block shows what the order earns, and the Shoes bonus on a plain order', async ({
    browser,
  }) => {
    const shoes = await memberPage(browser, 'customer');
    await addToCart(shoes, 'e2e-shoes');
    await shoes.goto('/cart/');
    await expect(shoes.locator('.yayloyalty-cart-earn__line')).toHaveText("You'll earn 300 Points with this order");
    await expect(shoes.locator('.yayloyalty-cart-earn__bonus')).toHaveCount(0);

    const plain = await memberPage(browser, 'customer');
    await addToCart(plain, 'e2e-plain');
    await plain.goto('/cart/');
    await expect(plain.locator('.yayloyalty-cart-earn__line')).toHaveText("You'll earn 100 Points with this order");
    // A plain member fails VIP too, and it quotes above the figure, so its line follows the Shoes one.
    await expect(plain.locator('.yayloyalty-cart-earn__bonus')).toHaveText([
      'Earn 300 Points when your order includes Shoes',
      'Earn 200 Points for VIP members',
    ]);
  });

  test('5: VIP $10 is locked for a plain member and off their redeem card; normal for vip', async ({ browser }) => {
    const plain = await memberPage(browser, 'customer');
    await plain.goto(`/${PAGE_SLUG}/`);
    const locked = row(section(plain, 'Ways to redeem'), 'VIP $10');
    await expect(locked).toHaveClass(/yayloyalty-rule--locked/);
    await expect(locked.locator('.yayloyalty-account__item-text')).toHaveText('for VIP members');

    await addToCart(plain, 'e2e-plain');
    await plain.goto('/cart/');
    await expect(card(plain)).toBeVisible();
    await expect(rewardBox(plain, 'VIP $10')).toHaveCount(0);

    const vip = await memberPage(browser, 'vip');
    await vip.goto(`/${PAGE_SLUG}/`);
    const open = row(section(vip, 'Ways to redeem'), 'VIP $10');
    await expect(open).not.toHaveClass(/yayloyalty-rule--locked/);
    await expect(open.locator('.yayloyalty-account__item-text')).toHaveText(/off your order/);

    await addToCart(vip, 'e2e-plain');
    await vip.goto('/cart/');
    await expect(rewardBox(vip, 'VIP $10')).toBeVisible();
  });

  test('6: advert_mode off and custom on the VIP campaign and reward', async ({ browser }) => {
    const guest = await guestPage(browser);
    const plain = await memberPage(browser, 'customer');

    try {
      await setCampaignAdvert(seed.vipCampaignId, 2, { advert_mode: 'off' });
      await setRewardAdvert({ advert_mode: 'off' });

      await guest.goto(`/${PAGE_SLUG}/`);
      await expect(purchaseRow(section(guest, 'Ways to earn')).locator('.yayloyalty-account__item-bonus')).toHaveText([
        SHOES_LINE,
      ]);
      await guest.goto('/product/e2e-plain/');
      await expect(guest.locator('.yayloyalty-product-earn__bonus')).toHaveCount(0);

      await plain.goto(`/${PAGE_SLUG}/`);
      await expect(row(section(plain, 'Ways to redeem'), 'VIP $10')).toHaveCount(0);

      await setCampaignAdvert(seed.vipCampaignId, 2, { advert_mode: 'custom', advert_text: 'for our insiders' });
      await setRewardAdvert({ advert_mode: 'custom', advert_text: 'for our insiders' });

      await guest.goto(`/${PAGE_SLUG}/`);
      await expect(purchaseRow(section(guest, 'Ways to earn')).locator('.yayloyalty-account__item-bonus')).toHaveText([
        SHOES_LINE,
        '2 Points / $1 for our insiders',
      ]);

      await plain.goto(`/${PAGE_SLUG}/`);
      const locked = row(section(plain, 'Ways to redeem'), 'VIP $10');
      await expect(locked).toHaveClass(/yayloyalty-rule--locked/);
      await expect(locked.locator('.yayloyalty-account__item-text')).toHaveText('for our insiders');
    } finally {
      await setCampaignAdvert(seed.vipCampaignId, 2, { advert_mode: 'auto' });
      await setRewardAdvert({ advert_mode: 'auto' });
    }
  });
});
