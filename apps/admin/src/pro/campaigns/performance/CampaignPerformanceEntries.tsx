import { useState, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import { parseWPCurrency, parseWPDate, parseWPTime, utcToDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { CampaignPerformanceEntry, CampaignPerformanceTrigger } from '@/lib/schema/campaign-performance.schema';
import { cn } from '@/lib/utils';
import { useDebouncedSearchCommit } from '@/hooks/useDebouncedSearchCommit';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Input } from '@/components/ui/input';
import { Pagination } from '@/components/ui/pagination';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { entriesEmptyText, entriesTitle } from './campaign-performance.copy';
import { useCampaignPerformanceEntriesQuery } from './campaign-performance.queries';

const PER_PAGE = 10;

type ColumnKey = 'reference' | 'customer' | 'subtotal' | 'points' | 'when';

/** Per-trigger column set — a lookup table, not three JSX branches. */
const COLUMNS: Record<CampaignPerformanceTrigger, ColumnKey[]> = {
  purchase: ['reference', 'customer', 'subtotal', 'points', 'when'],
  signup: ['customer', 'points', 'when'],
  review: ['reference', 'customer', 'points', 'when'],
};

const RIGHT_ALIGNED = new Set<ColumnKey>(['subtotal', 'points', 'when']);

function columnLabel(trigger: CampaignPerformanceTrigger, column: ColumnKey): string {
  switch (column) {
    case 'reference':
      return trigger === 'review' ? __('Review', 'yayloyalty') : __('Order', 'yayloyalty');
    case 'customer':
      return __('Customer', 'yayloyalty');
    case 'subtotal':
      return __('Subtotal', 'yayloyalty');
    case 'points':
      return __('Points', 'yayloyalty');
    case 'when':
      return __('When', 'yayloyalty');
  }
}

function renderCell(column: ColumnKey, entry: CampaignPerformanceEntry): ReactNode {
  switch (column) {
    case 'reference':
      return entry.reference ? (
        <a href={entry.reference.url} className="text-primary hover:underline">
          {entry.reference.label}
        </a>
      ) : (
        '—'
      );
    case 'customer':
      return entry.customer_name;
    case 'subtotal':
      return entry.subtotal === null ? '—' : parseWPCurrency(entry.subtotal);
    case 'points':
      return <span className="font-semibold tabular-nums">{formatPoints(entry.points)}</span>;
    case 'when': {
      const instant = utcToDate(entry.created_at);
      return (
        <span className="text-muted-foreground">
          {sprintf(
            /* translators: 1: date, 2: time. */
            __('%1$s, %2$s', 'yayloyalty'),
            parseWPDate(instant),
            parseWPTime(instant),
          )}
        </span>
      );
    }
  }
}

/**
 * Paged, searchable entries table that owns its query and page/search state. `entriesTotal` is the
 * unfiltered count from the summary: it drives the search bar, Export and the header count, so it
 * must not shrink when a search narrows the results.
 */
export function CampaignPerformanceEntries({
  campaignId,
  trigger,
  entriesTotal,
}: {
  campaignId: number;
  trigger: CampaignPerformanceTrigger;
  entriesTotal: number;
}) {
  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');

  const { commit: commitSearch } = useDebouncedSearchCommit((value) => {
    setSearch(value);
    setPage(1);
  });

  function handleSearchChange(value: string) {
    setSearchInput(value);
    commitSearch(value);
  }

  const query = useCampaignPerformanceEntriesQuery(campaignId, {
    page,
    perPage: PER_PAGE,
    search: search || undefined,
  });

  const columns = COLUMNS[trigger];
  const rows = query.data?.data ?? [];
  const totalPage = Math.max(1, query.data?.totalPage ?? 1);
  const showSearch = entriesTotal > 30;

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-center justify-between gap-3 space-y-0">
        <div>
          <CardTitle className="text-sm">{entriesTitle(trigger)}</CardTitle>
          <p className="text-muted-foreground text-xs">
            {sprintf(
              /* translators: %s: total number of entries. */
              __('%s in total', 'yayloyalty'),
              formatPoints(entriesTotal),
            )}
          </p>
        </div>
        <div className="flex items-center gap-2">
          {showSearch && (
            <Input
              type="search"
              className="w-64"
              placeholder={__('Search customer or id', 'yayloyalty')}
              value={searchInput}
              onChange={(event) => handleSearchChange(event.target.value)}
            />
          )}
          {entriesTotal > 0 && (
            // Inert: no export endpoint yet.
            <Button type="button" onClick={() => {}}>
              {sprintf(
                /* translators: %s: total number of entries. */
                __('Export all %s', 'yayloyalty'),
                formatPoints(entriesTotal),
              )}
            </Button>
          )}
        </div>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {query.isError ? (
          <ErrorState message={__('Could not load these entries.', 'yayloyalty')} onRetry={() => query.refetch()} />
        ) : (
          <>
            <div
              className={cn(
                'overflow-x-auto rounded-md border transition-opacity',
                query.isPlaceholderData && 'opacity-60',
              )}
            >
              <Table>
                <TableHeader>
                  <TableRow>
                    {columns.map((column) => (
                      <TableHead key={column} className={RIGHT_ALIGNED.has(column) ? 'text-right' : undefined}>
                        {columnLabel(trigger, column)}
                      </TableHead>
                    ))}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {query.isPending ? (
                    <EntriesSkeletonRows columns={columns} />
                  ) : rows.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={columns.length} className="text-muted-foreground py-10 text-center text-sm">
                        {search
                          ? sprintf(
                              /* translators: %s: the search term that matched nothing. */
                              __('No entries match "%s".', 'yayloyalty'),
                              search,
                            )
                          : entriesEmptyText(trigger)}
                      </TableCell>
                    </TableRow>
                  ) : (
                    rows.map((entry) => (
                      <TableRow key={entry.id}>
                        {columns.map((column) => (
                          <TableCell key={column} className={RIGHT_ALIGNED.has(column) ? 'text-right' : undefined}>
                            {renderCell(column, entry)}
                          </TableCell>
                        ))}
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </div>
            {totalPage > 1 && (
              <Pagination
                pageIndex={page - 1}
                pageCount={totalPage}
                canPreviousPage={page > 1}
                canNextPage={page < totalPage}
                onPreviousPage={() => setPage((current) => Math.max(1, current - 1))}
                onNextPage={() => setPage((current) => Math.min(totalPage, current + 1))}
                onPageChange={(pageIndex) => setPage(pageIndex + 1)}
                className="ml-auto"
              />
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}

function EntriesSkeletonRows({ columns }: { columns: ColumnKey[] }) {
  return (
    <>
      {Array.from({ length: PER_PAGE }).map((_, index) => (
        <TableRow key={index}>
          {columns.map((column) => (
            <TableCell key={column} className={RIGHT_ALIGNED.has(column) ? 'text-right' : undefined}>
              <Skeleton className={cn('h-3 w-20', RIGHT_ALIGNED.has(column) && 'ml-auto')} />
            </TableCell>
          ))}
        </TableRow>
      ))}
    </>
  );
}
