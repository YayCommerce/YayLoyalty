import { MultiSelectCombobox, MultiSelectComboboxOption } from '@/components/ui/custom/multi-select-combobox';

/**
 * MultiSelectCombobox for a static list held as an array, the shape every
 * condition value uses. Values must not contain commas: the combobox joins
 * them; slugs, gateway ids and ISO codes never do.
 */
export function OptionListSelect({
  value,
  onChange,
  options,
  placeholder,
  disabled,
}: {
  value: string[];
  onChange: (value: string[]) => void;
  options: MultiSelectComboboxOption[];
  placeholder?: string;
  disabled?: boolean;
}) {
  return (
    <MultiSelectCombobox
      value={value.join(',')}
      onChange={(next) => onChange(next ? next.split(',') : [])}
      options={options}
      placeholder={placeholder}
      disabled={disabled}
    />
  );
}
