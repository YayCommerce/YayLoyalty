import { useState } from 'react';

import { Command, CommandGroup, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent } from '@/components/ui/popover';

import {
  ComboboxBadge,
  ComboboxCheckbox,
  ComboboxIcon,
  ComboboxRemove,
  ComboboxTrigger,
} from '../combobox';
import { cn } from '@/lib/utils';

export interface MultiSelectComboboxOption {
  value: string;
  label: string;
}

export interface MultiSelectComboboxProps {
  /** Comma-separated selected values, e.g. "product,store" */
  value: string;
  onChange: (value: string) => void;
  options: MultiSelectComboboxOption[];
  placeholder?: string;
  disabled?: boolean;
}

/**
 * Static multi-select combobox: a fixed option list (unlike MultiProductPicker/
 * MultiCategoryPicker, which search a remote API) toggled via checkboxes in a
 * popover, with selections shown as removable badges in the trigger.
 */
export function MultiSelectCombobox({
  value,
  onChange,
  options,
  placeholder = 'Select…',
  disabled,
}: MultiSelectComboboxProps) {
  const [open, setOpen] = useState(false);

  const selectedValues = value ? value.split(',').filter(Boolean) : [];
  const selectedOptions = options.filter((o) => selectedValues.includes(o.value));

  const toggle = (optionValue: string) => {
    const next = selectedValues.includes(optionValue)
      ? selectedValues.filter((v) => v !== optionValue)
      : [...selectedValues, optionValue];
    onChange(next.join(','));
  };

  const remove = (optionValue: string) => {
    onChange(selectedValues.filter((v) => v !== optionValue).join(','));
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <ComboboxTrigger className="w-full" disabled={disabled} aria-expanded={open}>
        <span className={cn('flex flex-wrap gap-1', value.length > 0 && '-ml-2')}>
          {selectedOptions.length > 0 ? (
            selectedOptions.map((o) => (
              <ComboboxBadge key={o.value}>
                {o.label}
                <ComboboxRemove
                  onRemove={() => {
                    remove(o.value);
                  }}
                />
              </ComboboxBadge>
            ))
          ) : (
            <span className="text-muted-foreground">{placeholder}</span>
          )}
        </span>
        <ComboboxIcon />
      </ComboboxTrigger>
      <PopoverContent className="w-(--radix-popover-trigger-width) p-0" align="start">
        <Command>
          <CommandList>
            <CommandGroup>
              {options.map((o) => {
                const isSelected = selectedValues.includes(o.value);
                return (
                  <CommandItem
                    key={o.value}
                    value={o.value}
                    onSelect={() => toggle(o.value)}
                    className="gap-2"
                  >
                    <ComboboxCheckbox selected={isSelected} />
                    <span className="truncate">{o.label}</span>
                  </CommandItem>
                );
              })}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
