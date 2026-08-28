'use client';

import * as React from 'react';
import { type VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { focusVariants } from './variants/focus.variants';
import {
  inputGroupAddonVariants,
  inputGroupButtonVariants,
  InputGroupVariantProps,
  inputGroupVariants,
  inputVariants,
} from './variants/input.variants';

const InputGroup = React.forwardRef<HTMLDivElement, React.ComponentProps<'div'> & InputGroupVariantProps>(
  ({ className, size = 'medium', ...props }, ref) => {
    return (
      <div
        ref={ref}
        data-slot="input-group"
        role="group"
        className={cn(inputGroupVariants({ size }), focusVariants(), inputVariants({ variant: 'input' }), className)}
        {...props}
      />
    );
  },
);

const InputGroupAddon = React.forwardRef<
  HTMLDivElement,
  React.ComponentProps<'div'> & VariantProps<typeof inputGroupAddonVariants>
>(({ className, align = 'inline-start', ...props }, ref) => {
  return (
    <div
      ref={ref}
      role="group"
      data-slot="input-group-addon"
      data-align={align}
      className={cn(inputGroupAddonVariants({ align }), className)}
      onClick={(e) => {
        if ((e.target as HTMLElement).closest('button')) {
          return;
        }
        e.currentTarget.parentElement?.querySelector('input')?.focus();
      }}
      {...props}
    />
  );
});

const InputGroupButton = React.forwardRef<
  HTMLButtonElement,
  Omit<React.ComponentProps<typeof Button>, 'size'> & VariantProps<typeof inputGroupButtonVariants>
>(({ className, type = 'button', variant = 'ghost', size = 'large', ...props }, ref) => {
  return (
    <Button
      ref={ref}
      type={type}
      data-size={size}
      variant={variant}
      className={cn(inputGroupButtonVariants({ size }), className)}
      {...props}
    />
  );
});

function InputGroupText({ className, ...props }: React.ComponentProps<'span'>) {
  return (
    <span
      className={cn(
        "text-muted-foreground flex items-center gap-2 text-sm [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4",
        className,
      )}
      {...props}
    />
  );
}

const InputGroupInput = React.forwardRef<HTMLInputElement, React.ComponentProps<'input'>>(
  ({ className, ...props }, ref) => {
    return (
      <Input
        ref={ref}
        data-slot="input-group-control"
        className={cn(
          'h-auto flex-1 rounded-none border-0 bg-transparent py-0 shadow-none focus:outline-none focus-visible:ring-0 focus-visible:ring-offset-0 disabled:opacity-100 dark:bg-transparent',
          className,
        )}
        {...props}
      />
    );
  },
);

const InputGroupTextarea = React.forwardRef<HTMLTextAreaElement, React.ComponentProps<'textarea'>>(
  ({ className, ...props }, ref) => {
    return (
      <Textarea
        ref={ref}
        data-slot="input-group-control"
        className={cn(
          'flex-1 resize-none rounded-none border-0 bg-transparent py-2 shadow-none focus-visible:ring-0 focus-visible:ring-offset-0 dark:bg-transparent',
          className,
        )}
        {...props}
      />
    );
  },
);

export { InputGroup, InputGroupAddon, InputGroupButton, InputGroupText, InputGroupInput, InputGroupTextarea };
