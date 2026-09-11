import { ReactNode } from 'react';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Checkbox } from '@/components/ui/checkbox';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { MockCustomer } from '../customers-page.mock-data';
import type { SortDirection, SortKey } from '../customers-page.sort-rows';
import { CustomerTableRow } from './CustomerTableRow';
import { CustomersEmptyState } from './CustomersEmptyState';
import { CustomersSkeletonRows } from './CustomersSkeletonRows';

/** Column count depends on which optional column groups are on — one place,
 *  reused by the header and the "no results" row's colSpan. */
export function countColumns(showProColumns: boolean, showSelection: boolean) {
  // Customer, Balance, Pending, Lifetime earned, Last activity, Expires = 6 always.
  // Pro adds Tier + Referrals; selection adds the leading checkbox column
  // (off during the rebuild toggle even in Pro — see CustomerTableRow.tsx).
  return 6 + (showProColumns ? 2 : 0) + (showSelection ? 1 : 0);
}

type HeaderSelectionState = 'none' | 'partial' | 'all';

/**
 * Purely presentational: `rows` arrives already sorted AND paginated (one
 * page's worth) — see CustomersScreen.tsx, which owns both so sorting is
 * applied to the whole filtered set before slicing a page, not per-page.
 * This component only renders the caret state and reports clicks upward.
 */
export function CustomersTable({
  rows,
  isLoading,
  isStoreEmpty,
  noMatchContent,
  showProColumns,
  showSelection,
  staleBalances,
  headerSelection,
  onToggleAll,
  selected,
  onToggleRow,
  sortBy,
  sortDir,
  onSort,
}: {
  rows: MockCustomer[];
  /** `useCustomersDemoQuery` still in flight — real network latency, not a simulated delay. */
  isLoading: boolean;
  /** True store (no members at all) vs. search/filters matching nobody — different messages. */
  isStoreEmpty: boolean;
  /** What to show when filters narrow a non-empty store to zero rows — see CustomersNoMatchState.tsx. */
  noMatchContent?: ReactNode;
  showProColumns: boolean;
  /** Off during the rebuild toggle even in Pro — see CustomerTableRow.tsx. */
  showSelection: boolean;
  staleBalances: boolean;
  headerSelection: HeaderSelectionState;
  onToggleAll: () => void;
  selected: Set<number>;
  onToggleRow: (userId: number) => void;
  sortBy: SortKey;
  sortDir: SortDirection;
  onSort: (key: SortKey) => void;
}) {
  const columnCount = countColumns(showProColumns, showSelection);

  return (
    <div className="overflow-x-auto rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            {showSelection && (
              <TableHead>
                <Checkbox
                  checked={headerSelection === 'all' ? true : headerSelection === 'partial' ? 'indeterminate' : false}
                  onCheckedChange={onToggleAll}
                  aria-label={__('Select all rows on this page', 'yayloyalty')}
                />
              </TableHead>
            )}
            <SortableHead sortKey="name" label={__('Customer', 'yayloyalty')} sortBy={sortBy} sortDir={sortDir} onSort={onSort} />
            {showProColumns && (
              <SortableHead sortKey="tier" label={__('Tier', 'yayloyalty')} sortBy={sortBy} sortDir={sortDir} onSort={onSort} />
            )}
            <SortableHead sortKey="balance" label={__('Balance', 'yayloyalty')} align="right" sortBy={sortBy} sortDir={sortDir} onSort={onSort} />
            <SortableHead sortKey="pending" label={__('Pending', 'yayloyalty')} align="right" sortBy={sortBy} sortDir={sortDir} onSort={onSort} />
            <SortableHead sortKey="lifetime" label={__('Lifetime earned', 'yayloyalty')} align="right" sortBy={sortBy} sortDir={sortDir} onSort={onSort} />
            <SortableHead sortKey="last_activity" label={__('Last activity', 'yayloyalty')} align="right" sortBy={sortBy} sortDir={sortDir} onSort={onSort} />
            <SortableHead sortKey="expires" label={__('Expires', 'yayloyalty')} align="right" sortBy={sortBy} sortDir={sortDir} onSort={onSort} />
            {showProColumns && (
              <SortableHead sortKey="referrals" label={__('Referrals', 'yayloyalty')} align="right" sortBy={sortBy} sortDir={sortDir} onSort={onSort} />
            )}
          </TableRow>
        </TableHeader>
        <TableBody>
          {isLoading && <CustomersSkeletonRows columnCount={columnCount} />}
          {!isLoading && rows.length === 0 && (
            <TableRow>
              <TableCell colSpan={columnCount} className="h-auto py-12">
                {isStoreEmpty ? (
                  <CustomersEmptyState />
                ) : (
                  (noMatchContent ?? (
                    <p className="text-muted-foreground text-center text-sm">
                      {__('No customers match these filters.', 'yayloyalty')}
                    </p>
                  ))
                )}
              </TableCell>
            </TableRow>
          )}
          {!isLoading &&
            rows.map((row) => (
              <CustomerTableRow
                key={row.user_id}
                customer={row}
                showProColumns={showProColumns}
                showSelection={showSelection}
                selected={selected.has(row.user_id)}
                onToggle={onToggleRow}
                stale={staleBalances}
              />
            ))}
        </TableBody>
      </Table>
    </div>
  );
}

/** Column head with a real sort caret — click toggles asc/desc, clicking a new column switches to it. */
function SortableHead({
  sortKey,
  label,
  align,
  sortBy,
  sortDir,
  onSort,
}: {
  sortKey: SortKey;
  label: string;
  align?: 'right';
  sortBy: SortKey;
  sortDir: SortDirection;
  onSort: (key: SortKey) => void;
}) {
  const active = sortBy === sortKey;
  const Icon = active ? (sortDir === 'asc' ? ArrowUp : ArrowDown) : ArrowUpDown;

  return (
    <TableHead className={align === 'right' ? 'text-right' : undefined}>
      <button
        type="button"
        onClick={() => onSort(sortKey)}
        className={`hover:text-foreground inline-flex cursor-pointer items-center gap-1 text-xs tracking-wide uppercase ${active ? 'text-primary font-semibold' : 'text-muted-foreground'}`}
        aria-label={label}
      >
        {align === 'right' ? (
          <>
            {label}
            <Icon className="size-3" />
          </>
        ) : (
          <>
            <Icon className="size-3" />
            {label}
          </>
        )}
      </button>
    </TableHead>
  );
}
