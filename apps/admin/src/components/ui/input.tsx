import * as React from 'react';
import { InputHTMLAttributes } from 'react';

import { cn } from '@/lib/utils';
import { focusVariants } from './variants/focus.variants';
import { inputVariants } from './variants/input.variants';

type InputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'size'>;

const Input = React.forwardRef<HTMLInputElement, InputProps>(({ className, type, ...props }, ref) => {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        'file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input transition-default flex h-9 w-full min-w-0 rounded-md border bg-transparent p-2 pl-3 text-sm shadow-xs outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium focus:shadow-none focus-visible:shadow-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
        focusVariants(),
        inputVariants({ variant: 'input' }),
        className,
      )}
      {...props}
      ref={ref}
    />
  );
});

export { Input };
export type { InputProps };
