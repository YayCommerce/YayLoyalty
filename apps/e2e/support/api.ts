import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { expect, request, type APIRequestContext } from '@playwright/test';

import { BASE_URL } from '../playwright.config';

const AUTH_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../.auth');

export type LoyaltyCustomer = { balance: number; pending_points: number; lifetime_earned: number };
export type LedgerRow = { id: number; points: number; type: string };
export type OrderLine = { sku: 'e2e-widget' | 'e2e-gadget'; quantity?: number };

/** The admin's REST client, authenticated with the application password setup.sh wrote. */
export async function adminApi(): Promise<Api> {
  const password = readFileSync(path.join(AUTH_DIR, 'app-password'), 'utf8').trim();
  const context = await request.newContext({
    baseURL: `${BASE_URL}/wp-json/`,
    extraHTTPHeaders: { Authorization: `Basic ${Buffer.from(`admin:${password}`).toString('base64')}` },
  });

  return new Api(context);
}

export class Api {
  private productIds = new Map<string, number>();

  constructor(readonly context: APIRequestContext) {}

  async dispose(): Promise<void> {
    await this.context.dispose();
  }

  async get<T>(route: string): Promise<T> {
    return this.send<T>('GET', route);
  }

  async post<T>(route: string, data: object = {}): Promise<T> {
    return this.send<T>('POST', route, data);
  }

  async put<T>(route: string, data: object): Promise<T> {
    return this.send<T>('PUT', route, data);
  }

  async delete<T>(route: string): Promise<T> {
    return this.send<T>('DELETE', route);
  }

  private async send<T>(method: string, route: string, data?: object): Promise<T> {
    const response = await this.context.fetch(route, { method, data });

    expect(response.ok(), `${method} ${route}: ${response.status()} ${await response.text()}`).toBeTruthy();

    return (await response.json()) as T;
  }

  async productId(sku: OrderLine['sku']): Promise<number> {
    if (!this.productIds.has(sku)) {
      const [product] = await this.get<Array<{ id: number }>>(`wc/v3/products?sku=${sku}`);
      this.productIds.set(sku, product.id);
    }

    return this.productIds.get(sku)!;
  }

  /** A customer the admin creates, so the signup campaign never awards it. */
  async createCustomer(role = 'customer'): Promise<{ id: number; email: string; username: string; password: string }> {
    const tag = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
    const user = { email: `e2e-${tag}@example.com`, username: `e2e_${tag}`, password: `pw-${tag}-E2e!` };
    const { id } = await this.post<{ id: number }>('wc/v3/customers', {
      ...user,
      billing: billingAddress(user.email),
    });

    if (role !== 'customer') {
      await this.post(`wp/v2/users/${id}`, { roles: [role] });
    }

    return { id, ...user };
  }

  async deleteCustomer(id: number): Promise<void> {
    await this.delete(`wp/v2/users/${id}?force=true&reassign=1`);
  }

  async createOrder(customerId: number, lines: OrderLine[], status = 'pending'): Promise<number> {
    const lineItems = await Promise.all(
      lines.map(async (line) => ({ product_id: await this.productId(line.sku), quantity: line.quantity ?? 1 })),
    );
    const { id } = await this.post<{ id: number }>('wc/v3/orders', {
      customer_id: customerId,
      status,
      payment_method: 'cheque',
      billing: billingAddress('order@example.com'),
      line_items: lineItems,
    });

    return id;
  }

  async setOrderStatus(orderId: number, status: string): Promise<void> {
    await this.put(`wc/v3/orders/${orderId}`, { status });
  }

  async customer(userId: number): Promise<LoyaltyCustomer> {
    return this.get<LoyaltyCustomer>(`yayloyalty/v1/customers/${userId}`);
  }

  /** The customer's ledger rows, oldest first. */
  async ledger(userId: number): Promise<LedgerRow[]> {
    const { data } = await this.get<{ data: LedgerRow[] }>(`yayloyalty/v1/customers/${userId}/ledger?per_page=100`);

    return [...data].sort((a, b) => a.id - b.id);
  }

  /** Signed points of the customer's ledger rows, oldest first. */
  async ledgerPoints(userId: number): Promise<number[]> {
    return (await this.ledger(userId)).map((row) => row.points);
  }

  async settings<T extends object>(): Promise<T> {
    return this.get<T>('yayloyalty/v1/settings');
  }

  async saveSettings(settings: object): Promise<void> {
    await this.post('yayloyalty/v1/settings', settings);
  }

  /** Waits until the customer's totals match, since awards run in request hooks the browser does not wait for. */
  async expectPoints(userId: number, expected: Partial<LoyaltyCustomer>): Promise<void> {
    await expect
      .poll(async () => {
        const customer = await this.customer(userId);

        return Object.fromEntries(Object.keys(expected).map((key) => [key, customer[key as keyof LoyaltyCustomer]]));
      })
      .toEqual(expected);
  }
}

function billingAddress(email: string) {
  return {
    first_name: 'E2E',
    last_name: 'Shopper',
    address_1: '1 Test Street',
    city: 'San Francisco',
    state: 'CA',
    postcode: '94103',
    country: 'US',
    email,
    phone: '5555550100',
  };
}
