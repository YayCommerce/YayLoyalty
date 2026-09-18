import { Pagination } from '@/components/ui/pagination';
import { Skeleton } from '@/components/ui/skeleton';

/** Wraps the shared Pagination primitive, converting this screen's 1-based page into its 0-based pageIndex. */
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
