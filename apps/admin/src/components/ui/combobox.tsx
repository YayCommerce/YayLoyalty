import * as React from 'react';
import { CheckIcon, ChevronDown, XIcon } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { PopoverTrigger } from '@/components/ui/popover';
import { Badge } from './badge';
import { inputVariants } from './variants/input.variants';

function ComboboxTrigger({
  className,
  children,
  ...props
}: {
  className?: string;
  children?: React.ReactNode;
} & React.ComponentProps<typeof Button>) {
  return (
    <PopoverTrigger asChild>
      <Button
        variant="outline"
        role="combobox"
        className={cn(
          '[&_svg:not([class*="text-"])]:text-muted-foreground h-auto min-h-9 w-fit min-w-75 justify-between rounded-md px-2 py-1 font-normal [&_svg:not([class*="text-"])]:transition-colors',
          inputVariants({ variant: 'picker' }),
          className,
        )}
        {...props}
      >
        {children}
      </Button>
    </PopoverTrigger>
  );
}

function ComboboxBadge({ className, children }: { className?: string; children: React.ReactNode }) {
  return (
    <Badge
      variant="dimmed-outline"
      className={cn('h-6.5 gap-0.5 rounded-sm has-[svg]:pe-1 [&>svg]:pointer-events-auto', className)}
    >
      {children}
    </Badge>
  );
}

function ComboboxRemove({ className, onRemove }: { className?: string; onRemove: (e: React.MouseEvent) => void }) {
  return (
    <XIcon
      onMouseDown={(e) => {
        e.stopPropagation();
        e.preventDefault();
      }}
      onClick={(e) => {
        e.stopPropagation();
        e.preventDefault();
        onRemove(e);
      }}
      className={cn(
        'text-muted-foreground hover:text-destructive mx-0.5 size-3.5 stroke-[2.5px] transition-colors',
        className,
      )}
    />
  );
}

function ComboboxIcon({ className }: { className?: string }) {
  return <ChevronDown className={cn('size-4', className)} />;
}

function ComboboxCheckbox({ selected }: { selected: boolean }) {
  return (
    <div
      className="border-input data-[selected=true]:border-primary data-[selected=true]:bg-primary data-[selected=true]:text-primary-foreground transition-default pointer-events-none size-4 shrink-0 rounded-xs border select-none *:[svg]:opacity-0 data-[selected=true]:*:[svg]:opacity-100"
      data-selected={selected}
    >
      <CheckIcon className="size-3.5 text-current" />
    </div>
  );
}

export { ComboboxTrigger, ComboboxBadge, ComboboxRemove, ComboboxIcon, ComboboxCheckbox };
