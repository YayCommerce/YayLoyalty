import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { pagerSummary } from '../customer-drawer.copy';

/** Rows per page on every paged drawer tab, and the skeleton row count while one loads. */
export const DRAWER_PAGE_SIZE = 10;

/**
 * "1–10 of 4,812 entries · newest first" with newer and older buttons.
 * Not the shared Pagination, which is "Page X of Y" with a page input.
 */
export function DrawerTabPager({
  page,
  perPage,
  totalItems,
  onPageChange,
  disabled,
  isPending,
}: {
  page: number;
  perPage: number;
  totalItems: number;
  onPageChange: (page: number) => void;
  disabled: boolean;
  /** Blank while the first fetch is in flight. */
  isPending: boolean;
}) {
  // Nothing to page: the table's empty state already says so.
  if (!isPending && totalItems === 0) {
    return null;
  }

  const pageCount = Math.max(1, Math.ceil(totalItems / perPage));
  const from = (page - 1) * perPage + 1;
  const to = Math.min(page * perPage, totalItems);

  return (
    <div className="flex items-center justify-between gap-4">
      <span className="text-muted-foreground text-sm">{isPending ? '' : pagerSummary(from, to, totalItems)}</span>
      <div className="flex items-center gap-1">
        <Button
          variant="outline"
          size="icon-sm"
          onClick={() => onPageChange(page - 1)}
          disabled={disabled || page <= 1}
        >
          <ChevronLeftIcon className="size-4" />
        </Button>
        <Button
          variant="outline"
          size="icon-sm"
          onClick={() => onPageChange(page + 1)}
          disabled={disabled || page >= pageCount}
        >
          <ChevronRightIcon className="size-4" />
        </Button>
      </div>
    </div>
  );
}
