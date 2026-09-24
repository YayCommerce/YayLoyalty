import type { ReactNode } from 'react';
import { ChevronsUpDown } from 'lucide-react';
import { __, _n, sprintf } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';

/**
 * The "N selected | Bulk Actions ▾" part of a YayUiKits BulkActionBox, shared
 * by every table with a selection. Each screen passes only its own items.
 */
export function BulkActionsMenu({
  count,
  children,
  contentClassName,
}: {
  count: number;
  /** DropdownMenuItem elements. */
  children: ReactNode;
  contentClassName?: string;
}) {
  return (
    <>
      <span className="text-sm font-semibold">
        {sprintf(
          /* translators: %d: number of selected rows. */
          _n('%d selected', '%d selected', count, 'yayloyalty'),
          count,
        )}
      </span>
      <Separator orientation="vertical" className="ml-2 h-5!" />
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            className="hover:text-primary hover:bg-primary/6 group flex cursor-pointer items-center gap-1.5 px-2.5 pe-2 font-normal"
          >
            <span>{__('Bulk Actions', 'yayloyalty')}</span>
            <span className="group-hover:text-primary text-muted-foreground flex items-center">
              <ChevronsUpDown className="size-3.5 stroke-[2.5px]" />
            </span>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" sideOffset={9} className={cn('w-fit', contentClassName)}>
          {children}
        </DropdownMenuContent>
      </DropdownMenu>
    </>
  );
}
