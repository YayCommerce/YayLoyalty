import type { MultiSelectComboboxOption } from '@/components/ui/custom/multi-select-combobox';

/**
 * The bootstrap's static vocabularies (LocalizeHelper::get_meta()) as picker
 * options. Real roles, gateways, countries and currencies instead of free
 * text: a merchant cannot type a slug that silently never matches.
 */

function fromMap(map: Record<string, string>): MultiSelectComboboxOption[] {
  return Object.entries(map).map(([value, label]) => ({ value, label }));
}

/** WP roles, slug => translated name. */
export function roleOptions(): MultiSelectComboboxOption[] {
  return fromMap(window.yayLoyaltyMeta.wpMeta.roles);
}

/** Every registered WooCommerce gateway, id => admin title. */
export function paymentGatewayOptions(): MultiSelectComboboxOption[] {
  return fromMap(window.yayLoyaltyMeta.wcMeta.payment_gateways);
}

/** ISO country code => name, labelled with the code so a search by either works. */
export function countryOptions(): MultiSelectComboboxOption[] {
  return fromMap(window.yayLoyaltyMeta.wcMeta.countries).map((option) => ({
    ...option,
    label: `${option.label} (${option.value})`,
  }));
}

/** ISO 4217 code => name, the same list CampaignConditionSet validates against. */
export function currencyOptions(): MultiSelectComboboxOption[] {
  return fromMap(window.yayLoyaltyMeta.wcMeta.currencies).map((option) => ({
    ...option,
    label: `${option.value} — ${option.label}`,
  }));
}
