import * as React from 'react';
import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import { CheckIcon } from 'lucide-react';

import { cn } from '@/lib/utils';
import { focusVariants } from './variants/focus.variants';
import { inputVariants } from './variants/input.variants';

const Checkbox = React.forwardRef<
  React.ElementRef<typeof CheckboxPrimitive.Root>,
  React.ComponentPropsWithoutRef<typeof CheckboxPrimitive.Root>
>(({ className, ...props }, ref) => {
  return (
    <CheckboxPrimitive.Root
      ref={ref}
      data-slot="checkbox"
      className={cn(
        'peer dark:bg-input/30 disabled:border-input disabled:bg-accent relative flex size-4.5 shrink-0 cursor-pointer items-center justify-center rounded-xs border shadow-xs transition outline-none after:absolute after:-inset-3 disabled:cursor-not-allowed',
        'data-[state=checked]:bg-primary data-[state=checked]:hover:border-primary-accent data-[state=checked]:hover:bg-primary-accent dark:data-[state=checked]:bg-primary data-[state=checked]:border-primary data-[state=checked]:text-primary-foreground data-[state=checked]:disabled:opacity-60',
        focusVariants(),
        inputVariants({ variant: 'input' }),
        className,
      )}
      {...props}
    >
      <CheckboxPrimitive.Indicator
        data-slot="checkbox-indicator"
        className="grid place-content-center text-current transition-none [&>svg]:size-3.5"
      >
        <CheckIcon className="stroke-3" />
      </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
  );
});

export { Checkbox };
