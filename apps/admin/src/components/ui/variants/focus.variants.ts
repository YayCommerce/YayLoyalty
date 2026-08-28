import { cva, VariantProps } from 'class-variance-authority';

export const focusVariants = cva(
  'outline-none focus:outline-none aria-invalid:ring-destructive aria-invalid:not-focus-visible:border-destructive has-[[data-slot][aria-invalid=true]]:ring-destructive has-[[data-slot][aria-invalid=true]]:not-focus-within:border-destructive',
  {
    variants: {
      offset: {
        default:
          'focus-visible:ring-offset-2 ring-offset-background focus-visible:ring-[1.5px] has-[[data-slot=input-group-control]:focus-visible]:ring-offset-2 has-[[data-slot=input-group-control]:focus-visible]:ring-[1.5px]',
      },
      variant: {
        primary: '',
        destructive: 'ring-destructive hover:ring-destructive-accent',
        warning: 'ring-warning hover:ring-warning-accent',
        success: 'ring-success hover:ring-success-accent',
        none: 'focus-visible:ring-offset-0 focus-visible:ring-0',
      },
    },
    defaultVariants: {
      offset: 'default',
      variant: 'primary',
    },
  },
);

export type FocusVariantProps = VariantProps<typeof focusVariants>;
