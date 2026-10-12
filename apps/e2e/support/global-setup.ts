import { chromium, type FullConfig } from '@playwright/test';

export const ADMIN_STATE = new URL('../.auth/admin.json', import.meta.url).pathname;

/** Logs the admin in once and saves the session for every spec's admin page. */
export default async function globalSetup(config: FullConfig): Promise<void> {
  const browser = await chromium.launch();
  const page = await browser.newPage({ baseURL: config.projects[0].use.baseURL });

  await page.goto('/wp-login.php');
  await page.getByLabel('Username or Email Address').fill('admin');
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: 'Log In' }).click();
  // The dashboard's WooCommerce widgets load slowly; the redirect alone proves the login.
  await page.waitForURL(/wp-admin/, { waitUntil: 'commit' });
  await page.context().storageState({ path: ADMIN_STATE });
  await browser.close();
}
