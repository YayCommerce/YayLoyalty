import { ArrowDown, ArrowUp } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { SortDirection, SortKey } from '../customers-page.sort-rows';

/**
 * The mobile stand-in for the desktop table's sortable headers. It calls the same `sort.onChange(key)`,
 * so picking the active key flips the direction there too. Only the keys the server sorts by are offered.
 */
const SORT_LABELS: Record<SortKey, string> = {
  name: __('Customer', 'yayloyalty'),
  balance: __('Balance', 'yayloyalty'),
  pending: __('Pending', 'yayloyalty'),
  lifetime: __('Lifetime earned', 'yayloyalty'),
  last_activity: __('Last activity', 'yayloyalty'),
  expires: __('Expires', 'yayloyalty'),
  tier: __('Tier', 'yayloyalty'),
  referrals: __('Referrals', 'yayloyalty'),
};

const SORTABLE_KEYS: SortKey[] = ['name', 'balance', 'pending', 'lifetime', 'last_activity', 'expires'];

export function CustomersSortDropdown({
  by,
  dir,
  onChange,
  disabled,
}: {
  by: SortKey;
  dir: SortDirection;
  onChange: (key: SortKey) => void;
  disabled?: boolean;
}) {
  const DirectionIcon = dir === 'asc' ? ArrowUp : ArrowDown;

  return (
    <div className="flex items-center gap-2 text-sm">
      <span className="text-muted-foreground">{__('Sorted by', 'yayloyalty')}</span>
      <DropdownMenu>
        <DropdownMenuTrigger asChild disabled={disabled}>
          <Button variant="outline" size="sm" className="gap-1.5">
            {SORT_LABELS[by]}
            <DirectionIcon className="size-3.5" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start">
          <DropdownMenuRadioGroup value={by} onValueChange={(value) => onChange(value as SortKey)}>
            {SORTABLE_KEYS.map((key) => (
              <DropdownMenuRadioItem key={key} value={key}>
                {SORT_LABELS[key]}
              </DropdownMenuRadioItem>
            ))}
          </DropdownMenuRadioGroup>
        </DropdownMenuContent>
      </DropdownMenu>
      <span className="sr-only">
        {sprintf(
          /* translators: %s: the sort direction, "ascending" or "descending". */
          __('Currently sorted %s', 'yayloyalty'),
          dir === 'asc' ? __('ascending', 'yayloyalty') : __('descending', 'yayloyalty'),
        )}
      </span>
    </div>
  );
}
