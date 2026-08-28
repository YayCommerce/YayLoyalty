import * as React from 'react';
import * as RadioGroupPrimitive from '@radix-ui/react-radio-group';

import { cn } from '@/lib/utils';
import { focusVariants } from './variants/focus.variants';
import { inputVariants } from './variants/input.variants';

function RadioGroup({ className, ...props }: React.ComponentProps<typeof RadioGroupPrimitive.Root>) {
  return <RadioGroupPrimitive.Root data-slot="radio-group" className={cn('grid gap-3', className)} {...props} />;
}

const RadioGroupItem = React.forwardRef<
  React.ElementRef<typeof RadioGroupPrimitive.Item>,
  React.ComponentPropsWithoutRef<typeof RadioGroupPrimitive.Item>
>(({ className, ...props }, ref) => {
  return (
    <RadioGroupPrimitive.Item
      ref={ref}
      data-slot="radio-group-item"
      className={cn(
        'text-primary disabled:border-input disabled:bg-accent transition-default aspect-square size-4.5 shrink-0 rounded-full border shadow-xs outline-none disabled:cursor-not-allowed disabled:opacity-60',
        'data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:hover:bg-primary-accent data-[state=checked]:hover:border-primary-accent dark:data-[state=checked]:border-primary',
        focusVariants(),
        inputVariants({ variant: 'input' }),
        className,
      )}
      {...props}
    >
      <RadioGroupPrimitive.Indicator
        data-slot="radio-group-indicator"
        className="relative flex items-center justify-center"
      >
        <span
          data-slot="radio-group-indicator-inner"
          className="bg-background absolute top-1/2 left-1/2 size-2 -translate-1/2 rounded-full"
        />
      </RadioGroupPrimitive.Indicator>
    </RadioGroupPrimitive.Item>
  );
});

export { RadioGroup, RadioGroupItem };
