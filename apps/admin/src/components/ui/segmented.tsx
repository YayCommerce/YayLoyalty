import * as React from 'react';
import * as ToggleGroupPrimitive from '@radix-ui/react-toggle-group';
import { ToggleGroupSingleProps } from '@radix-ui/react-toggle-group';
import { cva, type VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';

const segmentedVariants = cva('', {
  variants: {
    shape: {
      default: 'rounded-full',
      square: 'rounded-md',
    },
    size: {
      default: 'h-9 min-w-9',
      sm: 'h-8  min-w-8',
      lg: 'h-10 min-w-10',
    },
  },
  defaultVariants: {
    shape: 'default',
    size: 'default',
  },
});

const SegmentedContext = React.createContext<VariantProps<typeof segmentedVariants>>({
  size: 'default',
  shape: 'default',
});

type SegmentedProps = Omit<ToggleGroupSingleProps, 'type'> & VariantProps<typeof segmentedVariants>;
function Segmented({ className, size, shape, children, ...props }: SegmentedProps) {
  return (
    <ToggleGroupPrimitive.Root
      data-slot="toggle-group"
      data-size={size}
      type="single"
      className={cn(
        segmentedVariants({ size, shape }),
        'bg-muted text-muted-foreground-600 inline-flex w-fit items-center justify-center gap-1 p-1',
        className,
      )}
      {...props}
    >
      <SegmentedContext.Provider value={{ size, shape }}>{children}</SegmentedContext.Provider>
    </ToggleGroupPrimitive.Root>
  );
}

const SegmentedItem = React.forwardRef<
  React.ElementRef<typeof ToggleGroupPrimitive.Item>,
  React.ComponentPropsWithoutRef<typeof ToggleGroupPrimitive.Item> & VariantProps<typeof segmentedVariants>
>(({ className, children, size, shape, ...props }, ref) => {
  const context = React.useContext(SegmentedContext);

  return (
    <ToggleGroupPrimitive.Item
      ref={ref}
      data-slot="toggle-group-item"
      data-size={context.size || size}
      data-shape={shape ?? context.shape ?? 'default'}
      className={cn(
        "data-[state=on]:bg-background data-[state=on]:text-foreground hover:bg-muted dark:data-[state=on]:text-foreground focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:outline-ring dark:data-[state=on]:border-input dark:data-[state=on]:bg-input/30 dark:text-muted-foreground inline-flex h-full cursor-pointer items-center justify-center gap-1.5 border border-transparent px-3 text-sm font-medium whitespace-nowrap transition focus-visible:ring-[3px] focus-visible:outline-1 disabled:pointer-events-none disabled:opacity-50 data-[shape=default]:rounded-full data-[shape=square]:rounded-sm data-[state=on]:shadow-sm [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className,
      )}
      {...props}
    >
      {children}
    </ToggleGroupPrimitive.Item>
  );
});

export { Segmented, SegmentedItem };
