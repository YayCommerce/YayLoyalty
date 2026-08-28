import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import clsx from 'clsx';
import { Loader2 } from 'lucide-react';

import { cn } from '@/lib/utils';
import { focusVariants } from '@/components/ui/variants/focus.variants';

const buttonVariants = cva(
  "inline-flex items-center justify-center gap-1 whitespace-nowrap rounded-md text-sm font-medium cursor-pointer transition-default disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none",
  {
    variants: {
      variant: {
        muted: 'border bg-muted text-muted-foreground-400',
        outline:
          'border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50',
        ghost: 'hover:bg-accent text-foreground bg-transparent focus-visible:text-primary',
        link: 'text-foreground hover:text-primary underline-offset-4 hover:underline',
        secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',

        primary: 'bg-primary text-primary-foreground shadow-xs hover:bg-primary-accent border border-transparent',
        'primary-soft': 'bg-primary/6 text-primary hover:text-primary-accent',
        'primary-outline': 'border border-primary text-primary hover:border-primary-accent hover:text-primary-accent',
        'primary-outline-fill':
          'border border-primary text-primary hover:bg-primary hover:text-primary-foreground hover:border-transparent shadow-xs',

        destructive: 'bg-destructive text-destructive-foreground shadow-xs hover:bg-destructive-accent',
        'destructive-soft': 'bg-destructive/6 text-destructive hover:text-destructive-accent',
        'destructive-outline':
          'border border-destructive text-destructive hover:border-destructive-accent hover:text-destructive-accent',

        success: 'bg-success text-success-foreground shadow-xs hover:bg-success-accent',
        'success-soft': 'bg-success/6 text-success hover:text-success-accent',
        'success-outline': 'border border-success text-success hover:border-success-accent hover:text-success-accent',

        warning: 'bg-warning text-warning-foreground shadow-xs hover:bg-warning-accent',
        'warning-soft': 'bg-warning/6 text-warning hover:text-warning-accent',
        'warning-outline': 'border border-warning text-warning hover:border-warning-accent hover:text-warning-accent',
      },
      size: {
        default: 'h-9 px-4 gap-1.5 py-2 has-[>svg]:px-3',
        sm: 'h-8 rounded-sm gap-1 px-3 has-[>svg]:px-2.5',
        lg: 'h-10 rounded-lg px-6 has-[>svg]:px-4 gap-1.5',
        icon: 'size-9',
        'icon-sm': 'size-8',
        'icon-lg': 'size-10',
      },
    },
    defaultVariants: {
      variant: 'primary',
      size: 'default',
    },
  },
);

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {
  asChild?: boolean;
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, asChild = false, type = 'button', ...props }, ref) => {
    const Comp = asChild ? Slot : 'button';
    return (
      <Comp
        data-slot="button"
        className={cn(
          focusVariants({ variant: getButtonFocusVariant(variant) }),
          buttonVariants({ variant, size, className }),
        )}
        type={type}
        {...props}
        ref={ref}
      />
    );
  },
);

function getButtonFocusVariant(variant: ButtonProps['variant']): VariantProps<typeof focusVariants>['variant'] {
  if (variant === 'destructive' || variant === 'destructive-outline' || variant === 'destructive-soft') {
    return 'destructive';
  } else if (variant === 'success' || variant === 'success-outline' || variant === 'success-soft') {
    return 'success';
  } else if (variant === 'warning' || variant === 'warning-outline' || variant === 'warning-soft') {
    return 'warning';
  } else {
    return 'primary';
  }
}

function LoadingButton({
  loading,
  className,
  size = 'default',
  children,
  ...props
}: ButtonProps & {
  loading: boolean;
}) {
  return (
    <Button className={clsx('relative', className)} size={size} {...props}>
      <Loader2
        data-slot="loading-indicator"
        data-size={size}
        data-loading={loading ? 'true' : 'false'}
        className="absolute animate-spin transition-opacity data-[loading=false]:opacity-0 data-[size=default]:size-4 data-[size=icon]:size-4 data-[size=icon-lg]:size-5 data-[size=icon-sm]:size-3.5 data-[size=lg]:size-5 data-[size=sm]:size-3.5"
      />
      <span
        data-slot="loading-text"
        data-size={size}
        data-loading={loading ? 'true' : 'false'}
        className="inline-flex items-center justify-center gap-[inherit] whitespace-nowrap transition-opacity data-[loading=true]:opacity-0"
      >
        {children}
      </span>
    </Button>
  );
}

export { Button, buttonVariants, LoadingButton, type ButtonProps };
