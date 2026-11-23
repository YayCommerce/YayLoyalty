import { ReactNode } from 'react';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import type { CustomerListRow } from '@/lib/schema/customer.schema';
import { cn } from '@/lib/utils';
import { Checkbox } from '@/components/ui/checkbox';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { HeaderSelectionState } from '../customers-page.selection';
import type { SortDirection, SortKey } from '../customers-page.sort';
import { CustomersCardList } from './CustomersCardList';
import { CustomersEmptyState } from './CustomersEmptyState';
import { CustomersSkeletonRows } from './CustomersSkeletonRows';
import { CustomersSortDropdown } from './CustomersSortDropdown';
import { CustomerTableRow } from './CustomerTableRow';

/** Column count for the header and the "no results" row's colSpan. */
export function countColumns(showSelection: boolean, showTiers: boolean) {
  // Six base columns, the checkbox column and the Tier column.
  return 6 + (showSelection ? 1 : 0) + (showTiers ? 1 : 0);
}

/**
 * Presentational: rows arrive sorted and paginated server-side. Below `sm` it shows the card list instead.
 * Both trees always mount and CSS picks one, since the codebase has no media-query hook.
 */
export function CustomersTable({
  rows,
  isLoading,
  isFetching,
  isStoreEmpty,
  noMatchContent,
  sort,
  selection,
  showTiers,
}: {
  rows: CustomerListRow[];
  /** No data yet. */
  isLoading: boolean;
  /** A background refetch behind placeholderData; dims the table. Not over the first-load skeleton. */
  isFetching: boolean;
  /** No members at all, as opposed to filters matching nobody. */
  isStoreEmpty: boolean;
  /** Shown when filters narrow a non-empty store to zero rows. */
  noMatchContent?: ReactNode;
  sort: {
    by: SortKey;
    dir: SortDirection;
    onChange: (key: SortKey) => void;
  };
  selection: {
    enabled: boolean;
    header: HeaderSelectionState;
    selected: Set<number>;
    onToggle: (userId: number) => void;
    onToggleAll: () => void;
  };
  /** Pro with tiers on: the Tier column and its sort. */
  showTiers: boolean;
}) {
  const { by: sortBy, dir: sortDir, onChange: onSort } = sort;
  const { enabled: showSelection, header: headerSelection, selected, onToggle: onToggleRow, onToggleAll } = selection;

  const columnCount = countColumns(showSelection, showTiers);
  const isEmpty = !isLoading && rows.length === 0;

  return (
    <>
      {/* Mobile: the sort dropdown stands in for the desktop headers' click-to-sort. */}
      <div className="flex flex-col gap-3 sm:hidden">
        <CustomersSortDropdown by={sortBy} dir={sortDir} onChange={onSort} disabled={isEmpty} showTiers={showTiers} />
        <CustomersCardList
          showTiers={showTiers}
          rows={rows}
          isLoading={isLoading}
          isFetching={isFetching}
          isStoreEmpty={isStoreEmpty}
          noMatchContent={noMatchContent}
        />
      </div>
      <div
        className={cn(
          'hidden overflow-x-auto rounded-md border transition-opacity sm:block',
          isFetching && !isLoading && 'opacity-60',
        )}
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
              {IS_PRO_BUILD && showTiers && (
                <SortableHead
                  sortKey="tier"
                  label={__('Tier', 'yayloyalty')}
                  sortBy={sortBy}
                  sortDir={sortDir}
                  onSort={onSort}
                  disabled={isEmpty}
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
                  showSelection={showSelection}
                  showTiers={showTiers}
                  selected={selected.has(row.user_id)}
                  onToggle={onToggleRow}
                />
              ))}
          </TableBody>
        </Table>
      </div>
    </>
  );
}

/** Column head with a sort caret; `disabled` drops to plain text rather than a dead-looking button. */
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
      {/* `flex h-full w-full`, not inline-flex: Chromium centres an inline-flex button 2px
          higher in a table cell when its first child is an SVG, so icon-first headers sat high. */}
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
