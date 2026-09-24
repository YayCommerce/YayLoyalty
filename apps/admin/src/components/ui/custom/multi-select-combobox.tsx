import { useState } from 'react';

import { cn } from '@/lib/utils';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent } from '@/components/ui/popover';
import { ComboboxBadge, ComboboxCheckbox, ComboboxIcon, ComboboxRemove, ComboboxTrigger } from '../combobox';

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
  /** Server-side search: the caller fetches `options` for each term, so the list is not filtered here. */
  search?: {
    onSearchChange: (term: string) => void;
    placeholder: string;
    emptyText: string;
    /** Labels for chosen values the current results leave out, so their badges keep a name. */
    selectedLabels: Record<string, string>;
  };
  /** Shown under the list, e.g. that only the first N results are listed. */
  footer?: React.ReactNode;
}

/**
 * Multi-select combobox: options toggled via checkboxes in a popover, with
 * selections shown as removable badges in the trigger. Static by default;
 * pass `search` when the caller loads options per search term.
 */
export function MultiSelectCombobox({
  value,
  onChange,
  options,
  placeholder = 'Select…',
  disabled,
  search,
  footer,
}: MultiSelectComboboxProps) {
  const [open, setOpen] = useState(false);

  const selectedValues = value ? value.split(',').filter(Boolean) : [];
  const selectedOptions = selectedValues.map((selected) => ({
    value: selected,
    label: options.find((o) => o.value === selected)?.label ?? search?.selectedLabels[selected] ?? selected,
  }));

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
        <Command shouldFilter={!search}>
          {search && <CommandInput placeholder={search.placeholder} onValueChange={search.onSearchChange} />}
          <CommandList>
            {search && <CommandEmpty>{search.emptyText}</CommandEmpty>}
            <CommandGroup>
              {options.map((o) => {
                const isSelected = selectedValues.includes(o.value);
                return (
                  <CommandItem key={o.value} value={o.value} onSelect={() => toggle(o.value)} className="gap-2">
                    <ComboboxCheckbox selected={isSelected} />
                    <span className="truncate">{o.label}</span>
                  </CommandItem>
                );
              })}
            </CommandGroup>
          </CommandList>
          {footer && <div className="text-muted-foreground border-t px-3 py-2 text-xs">{footer}</div>}
        </Command>
      </PopoverContent>
    </Popover>
  );
}
