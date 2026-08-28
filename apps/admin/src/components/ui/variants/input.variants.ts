import { cva, VariantProps } from 'class-variance-authority';

export const inputVariants = cva('border-input transition-default', {
  variants: {
    variant: {
      input:
        'focus-visible:ring-ring has-[[data-slot=input-group-control]:focus-visible]:ring-ring hover:border-muted-foreground focus-visible:hover:border-input has-[[data-slot=input-group-control]:focus-visible]:hover:border-input',
      picker: 'cursor-pointer shadow-xs hover:bg-muted-400 focus-visible:bg-muted-400 focus-visible:ring-ring',
    },
  },
  defaultVariants: {
    variant: 'input',
  },
});

export type InputVariantProps = VariantProps<typeof inputVariants>;

export const inputGroupVariants = cva(
  [
    'group/input-group border hover:border-muted-foreground border-input dark:bg-input/30 shadow-xs relative flex w-full items-center outline-none transition-default duration-300 min-w-0 has-[>textarea]:h-auto has-[>[data-align=block-end]]:h-auto has-[>[data-align=block-end]]:flex-col has-[>[data-align=block-start]]:h-auto has-[>[data-align=block-start]]:flex-col focus-visible:group-hover:border-border',
    'has-[[data-slot=input-group-control]:disabled]:pointer-events-none has-[[data-slot=input-group-control]:disabled]:cursor-not-allowed has-[[data-slot=input-group-control]:disabled]:opacity-50',
    'has-[>[data-align=inline-start]]:[&>input]:pl-2 has-[>[data-align=inline-end]]:[&>input]:pr-2',
  ],
  {
    variants: {
      size: {
        small: 'h-8 rounded-sm',
        medium: 'h-9 rounded-md',
        large: 'h-10 rounded-lg',
      },
    },
    defaultVariants: {
      size: 'medium',
    },
  },
);

export type InputGroupVariantProps = VariantProps<typeof inputGroupVariants>;

export const inputGroupAddonVariants = cva(
  "text-muted-foreground flex h-auto cursor-text items-center justify-center gap-2 py-1.5 text-sm font-medium select-none [&>svg:not([class*='size-'])]:size-4 [&>kbd]:rounded-[calc(var(--radius)-5px)]",
  {
    variants: {
      align: {
        'inline-start': 'order-first pl-3 has-[>button]:-ml-2 has-[>kbd]:ml-[-0.35rem]',
        'inline-end': 'order-last pr-3 has-[>button]:mr-[-0.45rem] has-[>kbd]:mr-[-0.35rem]',
        'block-start':
          'order-first w-full justify-start px-3 pt-3 [.border-b]:pb-3 group-has-[>input]/input-group:pt-2.5',
        'block-end': 'order-last w-full justify-start px-3 pb-3 [.border-t]:pt-3 group-has-[>input]/input-group:pb-2.5',
      },
    },
    defaultVariants: {
      align: 'inline-start',
    },
  },
);

export const inputGroupButtonVariants = cva('text-sm shadow-none flex gap-2 items-center', {
  variants: {
    size: {
      medium:
        "h-6 gap-1 px-2 rounded-[calc(var(--radius-md)-4px)] [&>svg:not([class*='size-'])]:size-3.5 has-[>svg]:px-2",
      large: 'h-7 px-2.5 gap-1.5 rounded-[calc(var(--radius-md)-4px)] has-[>svg]:px-2.5',
      'icon-md': 'size-6 rounded-[calc(var(--radius-md)-4px)] p-0 has-[>svg]:p-0',
      'icon-lg': 'size-7 rounded-[calc(var(--radius-md)-4px)] p-0 has-[>svg]:p-0',
    },
  },
  defaultVariants: {
    size: 'large',
  },
});
