import { ChevronLeft, ChevronRight, Ellipsis } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

/** 1, …, the current page and its neighbours, …, the last page; `null` is an ellipsis. */
function pageItems(page: number, pageCount: number): (number | null)[] {
  const pages = new Set([1, page - 1, page, page + 1, pageCount].filter((p) => p >= 1 && p <= pageCount));
  const sorted = [...pages].sort((a, b) => a - b);

  return sorted.flatMap((p, i) => (i > 0 && p - sorted[i - 1] > 1 ? [null, p] : [p]));
}

/** Previous, numbered pages and Next. Pages are 1-based. */
export function CustomersPagination({
  page,
  pageCount,
  isLoading,
  onPrevious,
  onNext,
  onPageChange,
  className,
}: {
  page: number;
  pageCount: number;
  isLoading: boolean;
  onPrevious: () => void;
  onNext: () => void;
  onPageChange: (page: number) => void;
  className?: string;
}) {
  if (isLoading) {
    return <Skeleton className={cn('h-9 w-64', className)} />;
  }

  return (
    <nav aria-label={__('Pagination', 'yayloyalty')} className={cn('flex items-center gap-1', className)}>
      <Button variant="ghost" disabled={page <= 1} onClick={onPrevious}>
        <ChevronLeft />
        {__('Previous', 'yayloyalty')}
      </Button>
      {pageItems(page, pageCount).map((item, i) =>
        item === null ? (
          <span key={`gap-${i}`} className="text-muted-foreground flex size-9 items-center justify-center" aria-hidden>
            <Ellipsis className="size-4" />
          </span>
        ) : (
          <Button
            key={item}
            variant={item === page ? 'outline' : 'ghost'}
            size="icon"
            aria-current={item === page ? 'page' : undefined}
            onClick={() => onPageChange(item)}
          >
            {item}
          </Button>
        ),
      )}
      <Button variant="ghost" disabled={page >= pageCount} onClick={onNext}>
        {__('Next', 'yayloyalty')}
        <ChevronRight />
      </Button>
    </nav>
  );
}
