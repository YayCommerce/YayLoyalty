import * as React from 'react';

import { cn } from '@/lib/utils';
import { focusVariants } from './variants/focus.variants';
import { inputVariants } from './variants/input.variants';

interface TextareaProps extends React.ComponentProps<'textarea'> {
  autoSize?: boolean;
}

const Textarea = React.forwardRef<HTMLTextAreaElement, TextareaProps>(
  ({ className, autoSize = false, ...props }, ref) => {
    return (
      <textarea
        ref={ref}
        data-slot="textarea"
        className={cn(
          'border-input placeholder:text-muted-foreground dark:bg-input/30 transition-default flex min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-sm font-normal shadow-xs outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
          autoSize && 'field-sizing-content',
          focusVariants(),
          inputVariants(),
          className,
        )}
        {...props}
      />
    );
  },
);

export { Textarea };
