import { __, sprintf } from '@wordpress/i18n';

import { MultiSelectCombobox } from '@/components/ui/custom/multi-select-combobox';
import { useTiersEnabled, useTiersQuery } from '../tiers/tiers.queries';

/** Mirrors CustomerRowFilters::TIER_NONE, the source of truth. */
const TIER_NONE = 'none';

/** Live tiers, lowest rank first, then "No tier". */
function useTierFilterOptions(): { value: string; label: string }[] {
  const tiers = useTiersQuery().data ?? [];

  return [
    ...[...tiers]
      .sort((a, b) => a.tier_rank - b.tier_rank)
      .map((tier) => ({ value: String(tier.id), label: tier.name })),
    { value: TIER_NONE, label: __('No tier', 'yayloyalty') },
  ];
}

/** The Customers toolbar's Tier filter. Renders nothing while tiers are off. */
export function CustomersTierFilter({
  value,
  onChange,
  disabled,
}: {
  value: string;
  onChange: (value: string) => void;
  disabled: boolean;
}) {
  const enabled = useTiersEnabled();
  const options = useTierFilterOptions();

  if (!enabled) return null;

  return (
    <div className="w-75">
      <MultiSelectCombobox
        value={value}
        onChange={onChange}
        options={options}
        placeholder={__('Tier', 'yayloyalty')}
        disabled={disabled}
      />
    </div>
  );
}

/** The tier filter's choices as text, such as "Tier: Silver, No tier"; null when it filters nothing. */
export function useTierFilterLabel(value: string): string | null {
  const options = useTierFilterOptions();
  const names = value
    .split(',')
    .filter(Boolean)
    .map((token) => options.find((option) => option.value === token)?.label)
    .filter((name): name is string => name !== undefined);

  if (names.length === 0) return null;

  return sprintf(
    /* translators: %s: tier names, comma-separated. */
    __('Tier: %s', 'yayloyalty'),
    names.join(', '),
  );
}
