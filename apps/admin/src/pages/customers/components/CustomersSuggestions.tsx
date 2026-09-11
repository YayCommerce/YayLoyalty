import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';

/**
 * Computed-looking "drop one filter" suggestions (4a). Counts are static
 * strings from `ScenarioConfig` — nothing here is recomputed.
 */
export function CustomersSuggestions({
  suggestions,
}: {
  suggestions: { withoutLabel: string; countLabel: string }[];
}) {
  return (
    <div className="flex w-full flex-col gap-2">
      {suggestions.map((suggestion) => (
        <div key={suggestion.withoutLabel} className="flex items-center gap-3 rounded-md border px-3 py-2 text-sm">
          <span>
            {__('Without', 'yayloyalty')} <strong>{suggestion.withoutLabel}</strong>
          </span>
          <span className="text-muted-foreground ml-auto font-semibold">{suggestion.countLabel}</span>
          <Button variant="link" size="sm">
            {__('Use this', 'yayloyalty')}
          </Button>
        </div>
      ))}
    </div>
  );
}
