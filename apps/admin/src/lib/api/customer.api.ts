import { api } from '@/lib/api/api';
import { parsePaginated } from '@/lib/api/paginated';
import { AdjustPointsInput, Customer, customerSchema } from '@/lib/schema/customer.schema';
import { LedgerEntry, ledgerEntrySchema } from '@/lib/schema/ledger.schema';

/**
 * Every response is `.parse()`d rather than cast.
 *
 * `.json<T>()` is an unchecked TypeScript assertion: it tells the compiler what
 * the shape is without checking. A null where a number is expected then reaches
 * a component and white-screens the page. Parsing turns that into a caught
 * error at the boundary.
 */

export async function getCustomer(userId: number): Promise<Customer> {
  return customerSchema.parse(await api.get(`customers/${userId}`).json());
}

export async function getCustomerLedger(
  userId: number,
  params: { page?: number; per_page?: number } = {},
) {
  const searchParams: Record<string, string> = {};

  if (params.page) searchParams.page = String(params.page);
  if (params.per_page) searchParams.per_page = String(params.per_page);

  const payload = await api.get(`customers/${userId}/ledger`, { searchParams }).json();

  return parsePaginated<typeof ledgerEntrySchema>(ledgerEntrySchema, payload);
}

export async function adjustCustomerPoints(
  userId: number,
  input: AdjustPointsInput,
): Promise<LedgerEntry> {
  return ledgerEntrySchema.parse(await api.post(`customers/${userId}/adjust`, { json: input }).json());
}
