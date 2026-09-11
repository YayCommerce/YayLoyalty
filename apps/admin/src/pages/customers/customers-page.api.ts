import { z } from 'zod';

import { api } from '@/lib/api/api';
import { parsePaginated } from '@/lib/api/paginated';
import type { MockCustomer } from './customers-page.mock-data';

/**
 * TEMPORARY DEMO DATA, FETCHED OVER A REAL HTTP ROUND TRIP.
 *
 * `GET /customers` returns the same fixed six rows every time — see
 * `CustomerRestController::get_items()` / `CustomerDemoRows.php` on the PHP
 * side, which this schema mirrors field-for-field. This exists so the page
 * has a genuine loading state to show (real network latency), not a
 * simulated `setTimeout`, per an explicit request — not because the demo
 * data became any more real.
 *
 * Deleted, along with the PHP method/class above, once a real Customers list
 * endpoint exists. Never import this outside `pages/customers/`.
 */
const customersDemoItemSchema = z.object({
  user_id: z.number().int(),
  name: z.string(),
  email: z.string(),
  balance: z.number().int(),
  pending_points: z.number().int(),
  lifetime_earned: z.number().int(),
  last_activity_label: z.string(),
  last_activity_minutes_ago: z.number(),
  expires_at: z.string().nullable(),
  tier: z.string(),
  referrals: z.number().int(),
}) satisfies z.ZodType<MockCustomer>;

export async function getCustomersDemo(): Promise<MockCustomer[]> {
  const payload = await api.get('customers').json();

  return parsePaginated(customersDemoItemSchema, payload).data;
}
