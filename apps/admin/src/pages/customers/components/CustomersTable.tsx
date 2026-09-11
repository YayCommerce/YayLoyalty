import { ReactNode, useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Checkbox } from '@/components/ui/checkbox';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { MockCustomer } from '../customers-page.mock-data';
import { defaultSortDirection, sortRows, type SortDirection, type SortKey } from '../customers-page.sort-rows';
import { CustomerTableRow } from './CustomerTableRow';
import { CustomersSkeletonRows } from './CustomersSkeletonRows';

/** Column count depends only on which optional column groups are on — one
 *  place, reused by the header, the skeleton body and any empty-state colSpan. */
export function countColumns({ showProColumns, showSelection }: { showProColumns: boolean; showSelection: boolean }) {
  // Customer, Balance, Pending, Lifetime earned, Last activity, Expires = 6 always.
  // Pro adds Tier + Referrals; selection adds the leading checkbox column.
  return 6 + (showProColumns ? 2 : 0) + (showSelection ? 1 : 0);
}

type HeaderSelectionState = 'none' | 'partial' | 'all';

export function CustomersTable({
  rows,
  body,
  showProColumns,
  showSelection,
  headerSelection,
  onToggleAll,
  selected,
  onToggleRow,
  staleBalances,
  emptyBlock,
  toolbarInert,
}: {
  rows: MockCustomer[];
  body: 'rows' | 'loading' | 'empty-store' | 'no-match';
  showProColumns: boolean;
  showSelection: boolean;
  headerSelection: HeaderSelectionState;
  onToggleAll: () => void;
  selected: Set<number>;
  onToggleRow: (userId: number) => void;
  staleBalances: boolean;
  emptyBlock?: ReactNode;
  toolbarInert: boolean;
}) {
  const columnCount = countColumns({ showProColumns, showSelection });

  // Real, client-side sort over the rows already loaded for this scenario —
  // see customers-page.sort-rows.ts for why this is the one interactive
  // control on the page that isn't a no-op. Balance/desc matches every
  // artboard's default active state.
  const [sortBy, setSortBy] = useState<SortKey>('balance');
  const [sortDir, setSortDir] = useState<SortDirection>('desc');

  function handleSort(key: SortKey) {
    if (key === sortBy) {
      setSortDir((current) => (current === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortBy(key);
      setSortDir(defaultSortDirection(key));
    }
  }

  const sortedRows = useMemo(() => sortRows(rows, sortBy, sortDir), [rows, sortBy, sortDir]);

  return (
    <div className="overflow-x-auto rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            {/* The design omits the header checkbox glyph entirely on the two
                inert/no-selection Pro scenarios (4a, 4b) — shown here disabled
                instead of adding a third column-visibility flag for it. */}
            {showSelection && (
              <TableHead>
                <Checkbox
                  checked={headerSelection === 'all' ? true : headerSelection === 'partial' ? 'indeterminate' : false}
                  onCheckedChange={onToggleAll}
                  disabled={toolbarInert}
                  aria-label={__('Select all rows on this page', 'yayloyalty')}
                />
              </TableHead>
            )}
            <SortableHead sortKey="name" label={__('Customer', 'yayloyalty')} sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
            {showProColumns && (
              <SortableHead sortKey="tier" label={__('Tier', 'yayloyalty')} sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
            )}
            <SortableHead sortKey="balance" label={__('Balance', 'yayloyalty')} align="right" sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
            <SortableHead sortKey="pending" label={__('Pending', 'yayloyalty')} align="right" sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
            <SortableHead sortKey="lifetime" label={__('Lifetime earned', 'yayloyalty')} align="right" sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
            <SortableHead sortKey="last_activity" label={__('Last activity', 'yayloyalty')} align="right" sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
            <SortableHead sortKey="expires" label={__('Expires', 'yayloyalty')} align="right" sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
            {showProColumns && (
              <SortableHead sortKey="referrals" label={__('Referrals', 'yayloyalty')} align="right" sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
            )}
          </TableRow>
        </TableHeader>
        <TableBody>
          {body === 'loading' && <CustomersSkeletonRows columnCount={columnCount} />}
          {(body === 'empty-store' || body === 'no-match') && (
            <TableRow>
              <TableCell colSpan={columnCount} className="h-auto py-12">
                {emptyBlock}
              </TableCell>
            </TableRow>
          )}
          {body === 'rows' && sortedRows.length === 0 && (
            <TableRow>
              <TableCell colSpan={columnCount} className="text-muted-foreground py-12 text-center">
                {__('No customers match these filters.', 'yayloyalty')}
              </TableCell>
            </TableRow>
          )}
          {body === 'rows' &&
            sortedRows.map((row) => (
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
