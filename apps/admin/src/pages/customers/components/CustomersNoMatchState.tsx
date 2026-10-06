import { __, sprintf } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { countLabel, type FilterDropSuggestion } from '../customers-page.filter-suggestions';

/** Filters combining to zero results, with real "drop this filter" counts; distinct from an empty store. */
export function CustomersNoMatchState({
  activeCount,
  suggestions,
  onUseSuggestion,
  onClearAll,
}: {
  activeCount: number;
  suggestions: FilterDropSuggestion[];
  onUseSuggestion: (suggestion: FilterDropSuggestion) => void;
  onClearAll: () => void;
}) {
  const title =
    activeCount > 1
      ? sprintf(
          /* translators: %d: number of active filters. */
          __('No customers match all %d filters', 'yayloyalty'),
          activeCount,
        )
      : __('No customers match this filter', 'yayloyalty');

  return (
    <Empty className="w-full border-none">
      <EmptyHeader>
        <EmptyTitle>{title}</EmptyTitle>
        {activeCount >= 2 && (
          <EmptyDescription>
            {suggestions.length > 0
              ? __('Try removing one of the filters below.', 'yayloyalty')
              : __('Removing any single filter still leaves nobody — try Clear all.', 'yayloyalty')}
          </EmptyDescription>
        )}
      </EmptyHeader>
      <EmptyContent className="max-w-lg gap-2.5">
        {suggestions.map((suggestion) => (
          <div key={suggestion.key} className="flex w-full items-center gap-3 rounded-md border px-3 py-2 text-sm">
            <span>
              {suggestion.phrasing === 'without' ? (
                <>
                  {__('Without', 'yayloyalty')} <strong>{suggestion.label}</strong>
                </>
              ) : (
                sprintf(
                  /* translators: %s: the search filter's label, such as Search "anna". */
                  __('%s on its own', 'yayloyalty'),
                  suggestion.label,
                )
              )}
            </span>
            <span className="text-muted-foreground ml-auto font-semibold">{countLabel(suggestion.count)}</span>
            <Button variant="link" size="sm" onClick={() => onUseSuggestion(suggestion)}>
              {__('Use this', 'yayloyalty')}
            </Button>
          </div>
        ))}
        <Button variant="outline" size="sm" onClick={onClearAll}>
          {__('Clear all filters', 'yayloyalty')}
        </Button>
      </EmptyContent>
    </Empty>
  );
}
