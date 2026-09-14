import { Pagination } from '@/components/ui/pagination';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Thin wrapper over the shared `Pagination` primitive (same one YayUiKits'
 * `TablePage.tsx` demos: "Page X of Y" + prev/next + a "Go to" number
 * input) — converts this screen's 1-based `page`/`pageCount` into that
 * component's 0-based `pageIndex`.
 */
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
    return <Skeleton className={`h-8 w-48 ${className ?? ''}`} />;
  }

  return (
    <Pagination
      pageIndex={page - 1}
      pageCount={pageCount}
      canPreviousPage={page > 1}
      canNextPage={page < pageCount}
      onPreviousPage={onPrevious}
      onNextPage={onNext}
      onPageChange={(pageIndex) => onPageChange(pageIndex + 1)}
      className={className}
    />
  );
}
