import { formatPercent, formatWPCurrency } from '@/lib/helpers/format.helper';

/** "$10" or "10%": what the friend gets, as the customer reads it, from settings or a referral's terms. */
export function discountText(terms: { discount_type?: unknown; discount_amount?: unknown }): string {
  const amount = Number(terms.discount_amount ?? 0);

  return terms.discount_type === 'percent' ? formatPercent(amount, 2) : formatWPCurrency(amount);
}
