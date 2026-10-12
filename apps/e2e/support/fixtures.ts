import { test as base, type Page } from '@playwright/test';

import { adminApi, type Api } from './api';
import { ADMIN_STATE } from './global-setup';

type Customer = Awaited<ReturnType<Api['createCustomer']>>;

type Fixtures = {
  api: Api;
  /** A fresh customer, deleted after the test. */
  customer: Customer;
  /** A page logged in as `customer`. */
  shopperPage: Page;
  /** A page logged in as the admin. */
  adminPage: Page;
};

export const test = base.extend<Fixtures>({
  api: async ({}, use) => {
    const api = await adminApi();
    await use(api);
    await api.dispose();
  },

  customer: async ({ api }, use) => {
    const customer = await api.createCustomer();
    await use(customer);
    await api.deleteCustomer(customer.id);
  },

  shopperPage: async ({ browser, customer }, use) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await logIn(page, customer.username, customer.password);
    await use(page);
    await context.close();
  },

  adminPage: async ({ browser }, use) => {
    const context = await browser.newContext({ storageState: ADMIN_STATE });
    await use(await context.newPage());
    await context.close();
  },
});

export { expect } from '@playwright/test';

export async function logIn(page: Page, username: string, password: string): Promise<void> {
  await page.goto('/my-account/');
  await page.locator('#username').fill(username);
  await page.locator('#password').fill(password);
  await page.getByRole('button', { name: 'Log in' }).click();
  await page.getByRole('link', { name: 'Log out' }).first().waitFor();
}
