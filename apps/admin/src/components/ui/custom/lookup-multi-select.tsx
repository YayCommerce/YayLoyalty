import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import { useLookupLabelsQuery, useLookupSearchQuery } from '@/lib/queries/lookup.queries';
import { LOOKUP_LIMIT, LookupEntity } from '@/lib/schema/lookup.schema';
import { useDebouncedSearchCommit } from '@/hooks/useDebouncedSearchCommit';
import { MultiSelectCombobox } from '@/components/ui/custom/multi-select-combobox';

/**
 * Server-searched multi-select over one lookup entity (products, categories,
 * customers), holding ids. Labels arrive with search results and, for ids
 * that were stored before any search ran, from one labels request; both are
 * remembered so a chosen badge never loses its name when the list moves on.
 */
export function LookupMultiSelect({
  entity,
  value,
  onChange,
  placeholder,
  max,
  disabled,
}: {
  entity: LookupEntity;
  value: number[];
  onChange: (ids: number[]) => void;
  placeholder: string;
  /** Selections past this are ignored; the footer says so. */
  max?: number;
  disabled?: boolean;
}) {
  const [term, setTerm] = useState('');
  const { commit } = useDebouncedSearchCommit(setTerm);
  const [labels, setLabels] = useState<Record<string, string>>({});

  const searchQuery = useLookupSearchQuery(entity, term);
  const unlabelled = value.filter((id) => !(String(id) in labels));
  const labelsQuery = useLookupLabelsQuery(entity, unlabelled);

  useEffect(() => {
    const seen = [...(searchQuery.data ?? []), ...(labelsQuery.data ?? [])];

    if (seen.length === 0) {
      return;
    }

    setLabels((current) => {
      const next = { ...current };
      seen.forEach((item) => {
        next[String(item.id)] = item.label;
      });
      return next;
    });
  }, [searchQuery.data, labelsQuery.data]);

  const results = searchQuery.data ?? [];
  const options = results.map((item) => ({ value: String(item.id), label: item.label }));

  function handleChange(joined: string) {
    const ids = joined ? joined.split(',').map(Number) : [];
    onChange(max ? ids.slice(0, max) : ids);
  }

  return (
    <MultiSelectCombobox
      value={value.join(',')}
      onChange={handleChange}
      options={options}
      placeholder={placeholder}
      disabled={disabled}
      search={{
        onSearchChange: commit,
        placeholder: __('Type to search…', 'yayloyalty'),
        emptyText: searchQuery.isPending ? __('Searching…', 'yayloyalty') : __('No matches.', 'yayloyalty'),
        selectedLabels: labels,
      }}
      footer={footerText(results.length, max)}
    />
  );
}

function footerText(resultCount: number, max: number | undefined): string | undefined {
  if (resultCount >= LOOKUP_LIMIT) {
    return sprintf(
      /* translators: %d: how many matches are listed. */
      __('Showing the first %d matches — type to narrow the list.', 'yayloyalty'),
      LOOKUP_LIMIT,
    );
  }

  if (max) {
    return sprintf(
      /* translators: %d: the most ids this field accepts. */
      __('Up to %d can be chosen.', 'yayloyalty'),
      max,
    );
  }

  return undefined;
}
