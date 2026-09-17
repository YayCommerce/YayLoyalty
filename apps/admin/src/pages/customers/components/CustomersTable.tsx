import { ReactNode } from 'react';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import type { CustomerListRow } from '@/lib/schema/customer.schema';
import { cn } from '@/lib/utils';
import { Checkbox } from '@/components/ui/checkbox';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { HeaderSelectionState } from '../customers-page.selection';
import type { SortDirection, SortKey } from '../customers-page.sort-rows';
import { CustomersEmptyState } from './CustomersEmptyState';
import { CustomersSkeletonRows } from './CustomersSkeletonRows';
import { CustomerTableRow } from './CustomerTableRow';

/** Column count depends on which optional column groups are on — one place,
 *  reused by the header and the "no results" row's colSpan. */
export function countColumns(showProColumns: boolean, showSelection: boolean) {
  // Customer, Balance, Pending, Lifetime earned, Last activity, Expires = 6 always.
  // Pro adds Tier + Referrals; selection adds the leading checkbox column
  // (off during the rebuild toggle even in Pro — see CustomerTableRow.tsx).
  return 6 + (showProColumns ? 2 : 0) + (showSelection ? 1 : 0);
}

/**
 * Purely presentational: `rows` arrives already sorted AND paginated (one
 * page's worth) — see CustomersPage.tsx, which owns both as server query
 * params (`orderby`/`order`/`page`), so sorting is applied to the whole
 * filtered set server-side before slicing a page, not per-page here. This
 * component only renders the caret state and reports clicks upward.
 *
 * `sort`/`selection` grouped (mirrors CustomersScreen.tsx's own props) since
 * each is really one concern spread across several values.
 */
export function CustomersTable({
  rows,
  isLoading,
  isFetching,
  isStoreEmpty,
  noMatchContent,
  showProColumns,
  staleBalances,
  sort,
  selection,
}: {
  rows: CustomerListRow[];
  /** `useCustomersQuery` still in flight — real network latency, not a simulated delay. */
  isLoading: boolean;
  /** A background page/sort/filter refetch while `placeholderData` keeps the previous
   *  rows on screen — the whole bordered table dims so that work is visible without a
   *  spinner or a layout shift. Not applied over the first-load skeleton. */
  isFetching: boolean;
  /** True store (no members at all) vs. search/filters matching nobody — different messages. */
  isStoreEmpty: boolean;
  /** What to show when filters narrow a non-empty store to zero rows — see CustomersNoMatchState.tsx. */
  noMatchContent?: ReactNode;
  showProColumns: boolean;
  staleBalances: boolean;
  sort: {
    by: SortKey;
    dir: SortDirection;
    onChange: (key: SortKey) => void;
  };
  selection: {
    /** Off during the rebuild toggle even in Pro — see CustomerTableRow.tsx. */
    enabled: boolean;
    header: HeaderSelectionState;
    selected: Set<number>;
    onToggle: (userId: number) => void;
    onToggleAll: () => void;
  };
}) {
  const { by: sortBy, dir: sortDir, onChange: onSort } = sort;
  const { enabled: showSelection, header: headerSelection, selected, onToggle: onToggleRow, onToggleAll } = selection;

  const columnCount = countColumns(showProColumns, showSelection);
  // Nothing to sort or select when the body isn't showing real rows (loading
  // skeleton, empty store, no filter match) — headers go back to plain text
  // and the select-all checkbox disables, rather than staying clickable over
  // an empty table.
  const isEmpty = !isLoading && rows.length === 0;

  return (
    <div
      className={cn('overflow-x-auto rounded-md border transition-opacity', isFetching && !isLoading && 'opacity-60')}
    >
      <Table>
        <TableHeader>
          <TableRow>
            {showSelection && (
              <TableHead>
                <Checkbox
                  checked={headerSelection === 'all' ? true : headerSelection === 'partial' ? 'indeterminate' : false}
                  onCheckedChange={onToggleAll}
                  disabled={isEmpty}
                  aria-label={__('Select all rows on this page', 'yayloyalty')}
                />
              </TableHead>
            )}
            <SortableHead
              sortKey="name"
              label={__('Customer', 'yayloyalty')}
              sortBy={sortBy}
              sortDir={sortDir}
              onSort={onSort}
              disabled={isEmpty}
            />
            {showProColumns && (
              // Always disabled, not just when isEmpty: no tiers table exists yet, so there
              // is no real column to sort — the cells below only show a UI-check sample
              // value derived from balance (see customers-page.tier-sample-data.ts).
              <SortableHead
                sortKey="tier"
                label={__('Tier', 'yayloyalty')}
                sortBy={sortBy}
                sortDir={sortDir}
                onSort={onSort}
                disabled
              />
            )}
            <SortableHead
              sortKey="balance"
              label={__('Balance', 'yayloyalty')}
              align="right"
              sortBy={sortBy}
              sortDir={sortDir}
              onSort={onSort}
              disabled={isEmpty}
            />
            <SortableHead
              sortKey="pending"
              label={__('Pending', 'yayloyalty')}
              align="right"
              sortBy={sortBy}
              sortDir={sortDir}
              onSort={onSort}
              disabled={isEmpty}
            />
            <SortableHead
              sortKey="lifetime"
              label={__('Lifetime earned', 'yayloyalty')}
              align="right"
              sortBy={sortBy}
              sortDir={sortDir}
              onSort={onSort}
              disabled={isEmpty}
            />
            <SortableHead
              sortKey="last_activity"
              label={__('Last activity', 'yayloyalty')}
              align="right"
              sortBy={sortBy}
              sortDir={sortDir}
              onSort={onSort}
              disabled={isEmpty}
            />
            <SortableHead
              sortKey="expires"
              label={__('Expires', 'yayloyalty')}
              align="right"
              sortBy={sortBy}
              sortDir={sortDir}
              onSort={onSort}
              disabled={isEmpty}
            />
            {showProColumns && (
              // Always disabled: no referral-count column exists yet — see CustomerTableRow.tsx.
              <SortableHead
                sortKey="referrals"
                label={__('Referrals', 'yayloyalty')}
                align="right"
                sortBy={sortBy}
                sortDir={sortDir}
                onSort={onSort}
                disabled
              />
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

/**
 * Column head with a real sort caret — click toggles asc/desc, clicking a
 * new column switches to it. `disabled` (nothing in the body to sort) drops
 * to plain, non-interactive text with no caret rather than a dead-looking
 * clickable button.
 */
function SortableHead({
  sortKey,
  label,
  align,
  sortBy,
  sortDir,
  onSort,
  disabled,
}: {
  sortKey: SortKey;
  label: string;
  align?: 'right';
  sortBy: SortKey;
  sortDir: SortDirection;
  onSort: (key: SortKey) => void;
  disabled?: boolean;
}) {
  if (disabled) {
    return (
      <TableHead className={align === 'right' ? 'text-right' : undefined}>
        <span className="text-muted-foreground text-xs tracking-wide uppercase">{label}</span>
      </TableHead>
    );
  }

  const active = sortBy === sortKey;
  const Icon = active ? (sortDir === 'asc' ? ArrowUp : ArrowDown) : ArrowUpDown;

  return (
    <TableHead className={align === 'right' ? 'text-right' : undefined}>
      {/* `flex h-full w-full` (not `inline-flex`), not `text-align` on the <th>, does the
       *  centering here — verified live in Chrome (see git history / PR discussion) that an
       *  `inline-flex` button inside a table-cell with `vertical-align:middle` centers 2px
       *  higher when its first child is the icon (SVG) vs. the label (text): the icon-first
       *  headers (Customer, Tier) sat visibly higher than the label-first ones (Balance,
       *  Pending, ...), a real Chromium quirk, not something `items-center` alone fixes.
       *  Filling the cell's full height/width sidesteps it — there's no leftover space left
       *  for that baseline synthesis to misplace. */}
      <button
        type="button"
        onClick={() => onSort(sortKey)}
        className={`hover:text-foreground flex h-full w-full cursor-pointer items-center gap-1 text-xs font-medium tracking-wide uppercase ${align === 'right' ? 'justify-end' : 'justify-start'} ${active ? 'text-primary' : 'text-muted-foreground'}`}
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
