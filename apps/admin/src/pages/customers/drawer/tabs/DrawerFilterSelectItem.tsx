import * as SelectPrimitive from '@radix-ui/react-select';
import { CheckIcon } from 'lucide-react';

import { formatCount } from '@/lib/helpers/points.helper';
import { cn } from '@/lib/utils';

/**
 * A filter option with its count at the right edge. Only the label sits in ItemText, so the trigger shows the label
 * alone. Built on the Radix primitive because the shared SelectItem puts all children in ItemText.
 */
export function DrawerFilterSelectItem({ value, label, count }: { value: string; label: string; count?: number }) {
  return (
    <SelectPrimitive.Item
      value={value}
      className={cn(
        'focus:bg-accent focus:text-accent-foreground data-[state=checked]:text-primary hover:bg-accent relative flex w-full cursor-default items-center gap-4 rounded-sm py-1.5 pr-8 pl-2 text-sm outline-hidden select-none',
        count === 0 && 'text-muted-foreground',
      )}
    >
      <SelectPrimitive.ItemText>{label}</SelectPrimitive.ItemText>
      {count !== undefined && (
        <span className="text-muted-foreground ml-auto text-xs tabular-nums">{formatCount(count)}</span>
      )}
      <span className="absolute right-2 flex size-3.5 items-center justify-center">
        <SelectPrimitive.ItemIndicator>
          <CheckIcon className="text-primary size-3.5 stroke-[2.5px]" />
        </SelectPrimitive.ItemIndicator>
      </span>
    </SelectPrimitive.Item>
  );
}
