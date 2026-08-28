import * as React from 'react';
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { NumberInputInput, NumberInputRoot } from '@/components/ui/number-input';

interface PaginationProps extends React.ComponentProps<'div'> {
  pageIndex: number;
  pageCount: number;
  onPreviousPage: () => void;
  onNextPage: () => void;
  onPageChange: (page: number) => void;
  canPreviousPage: boolean;
  canNextPage: boolean;
  disabled?: boolean;
}

function Pagination({
  className,
  pageIndex,
  pageCount,
  onPreviousPage,
  onNextPage,
  onPageChange,
  canPreviousPage,
  canNextPage,
  disabled = false,
  ...props
}: PaginationProps) {
  const currentPage = pageIndex + 1;

  return (
    <div data-slot="pagination" className={cn('flex items-center gap-4', className)} {...props}>
      <span>
        Page {currentPage} of {pageCount}
      </span>

      <div className="flex items-center gap-1">
        <Button variant="outline" size="icon" onClick={onPreviousPage} disabled={!canPreviousPage || disabled}>
          <ChevronLeftIcon className="size-4" />
        </Button>
        <Button variant="outline" size="icon" onClick={onNextPage} disabled={!canNextPage || disabled}>
          <ChevronRightIcon className="size-4" />
        </Button>
      </div>

      <div className="flex items-center gap-2">
        <span>Go to</span>
        <NumberInputRoot
          min={1}
          max={pageCount}
          value={currentPage}
          onValueChange={(value) => {
            const page = value ? Number(value) - 1 : 0;
            if (page >= 0 && page < pageCount) {
              onPageChange(page);
            }
          }}
          className="w-15 focus-visible:ring-0"
          disabled={disabled || pageCount <= 1}
        >
          <NumberInputInput />
        </NumberInputRoot>
      </div>
    </div>
  );
}

export { Pagination };
