import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { focusVariants } from '@/components/ui/variants/focus.variants';
import { inputVariants } from '@/components/ui/variants/input.variants';

const attachmentVariants = cva(
  'group/attachment relative flex w-fit max-w-full min-w-0 shrink-0 flex-wrap rounded-2xl border bg-card text-card-foreground transition-colors focus-within:ring-1 focus-within:ring-ring/30 has-[>a,>button]:hover:bg-muted/50 data-[state=error]:border-destructive/30 data-[state=idle]:border-dashed',
  {
    variants: {
      size: {
        default:
          'gap-2 text-sm has-data-[slot=attachment-content]:px-2.5 has-data-[slot=attachment-content]:py-2 has-data-[slot=attachment-media]:p-2',
        sm: 'gap-2.5 text-xs has-data-[slot=attachment-content]:px-2 has-data-[slot=attachment-content]:py-1.5 has-data-[slot=attachment-media]:p-1.5',
        xs: 'gap-1.5 rounded-xl text-xs has-data-[slot=attachment-content]:px-1.5 has-data-[slot=attachment-content]:py-1 has-data-[slot=attachment-media]:p-1',
      },
      orientation: {
        horizontal: 'min-w-40 items-center',
        vertical: 'w-24 flex-col has-data-[slot=attachment-content]:w-30',
      },
    },
  },
);

function Attachment({
  className,
  state = 'done',
  size = 'default',
  orientation = 'horizontal',
  ...props
}: React.ComponentProps<'div'> &
  VariantProps<typeof attachmentVariants> & {
    state?: 'idle' | 'uploading' | 'processing' | 'error' | 'done';
  }) {
  return (
    <div
      data-slot="attachment"
      data-state={state}
      data-size={size}
      data-orientation={orientation}
      className={cn(
        attachmentVariants({ size, orientation }),
        focusVariants(),
        inputVariants({ variant: 'input' }),
        className,
      )}
      {...props}
    />
  );
}

const attachmentMediaVariants = cva(
  "relative flex aspect-square w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted text-foreground group-data-[orientation=vertical]/attachment:w-full group-data-[size=sm]/attachment:w-8 group-data-[size=xs]/attachment:w-7 group-data-[size=xs]/attachment:rounded-md group-data-[state=error]/attachment:bg-destructive/10 group-data-[state=error]/attachment:text-destructive group-data-[orientation=vertical]/attachment:*:data-[slot=spinner]:size-6! [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 group-data-[orientation=vertical]/attachment:[&_svg:not([class*='size-'])]:size-6 group-data-[size=xs]/attachment:[&_svg:not([class*='size-'])]:size-3.5",
  {
    variants: {
      variant: {
        icon: '',
        image:
          'opacity-60 group-data-[state=done]/attachment:opacity-100 group-data-[state=idle]/attachment:opacity-100 *:[img]:aspect-square *:[img]:w-full *:[img]:object-cover',
      },
    },
    defaultVariants: {
      variant: 'icon',
    },
  },
);

function AttachmentMedia({
  className,
  variant = 'icon',
  ...props
}: React.ComponentProps<'div'> & VariantProps<typeof attachmentMediaVariants>) {
  return (
    <div
      data-slot="attachment-media"
      data-variant={variant}
      className={cn(attachmentMediaVariants({ variant }), className)}
      {...props}
    />
  );
}

function AttachmentContent({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="attachment-content"
      className={cn(
        'max-w-full min-w-0 flex-1 leading-tight group-data-[orientation=vertical]/attachment:px-1',
        className,
      )}
      {...props}
    />
  );
}

function AttachmentTitle({ className, ...props }: React.ComponentProps<'span'>) {
  return (
    <span
      data-slot="attachment-title"
      className={cn(
        'group-data-[state=processing]/attachment:shimmer group-data-[state=uploading]/attachment:shimmer block max-w-full min-w-0 truncate font-medium',
        className,
      )}
      {...props}
    />
  );
}

function AttachmentDescription({ className, ...props }: React.ComponentProps<'span'>) {
  return (
    <span
      data-slot="attachment-description"
      className={cn(
        'text-muted-foreground group-data-[state=error]/attachment:text-destructive/80 mt-0.5 block min-w-0 truncate text-xs',
        'max-w-full',
        className,
      )}
      {...props}
    />
  );
}

function AttachmentActions({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="attachment-actions"
      className={cn(
        'relative z-20 flex shrink-0 items-center group-data-[orientation=vertical]/attachment:absolute group-data-[orientation=vertical]/attachment:top-3 group-data-[orientation=vertical]/attachment:right-3 group-data-[orientation=vertical]/attachment:gap-1',
        className,
      )}
      {...props}
    />
  );
}

function AttachmentAction({ className, variant, size = 'icon-sm', ...props }: React.ComponentProps<typeof Button>) {
  return (
    <Button
      data-slot="attachment-action"
      variant={variant ?? 'ghost'}
      size={size}
      className={cn(className)}
      {...props}
    />
  );
}

interface AttachmentTriggerProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  asChild?: boolean;
}

function AttachmentTrigger({ className, asChild = false, type, ...props }: AttachmentTriggerProps) {
  const Comp = asChild ? Slot : 'button';

  return (
    <Comp
      type={asChild ? type : (type ?? 'button')}
      className={cn('absolute inset-0 z-10 outline-none', className)}
      data-slot="attachment-trigger"
      {...props}
    />
  );
}

function AttachmentGroup({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="attachment-group"
      className={cn(
        'scroll-fade-x flex min-w-0 snap-x snap-mandatory scroll-px-1 scrollbar-none gap-3 overflow-x-auto overscroll-x-contain py-1 *:data-[slot=attachment]:flex-none *:data-[slot=attachment]:snap-start',
        className,
      )}
      {...props}
    />
  );
}

type AttachmentDropzoneError = 'invalid_type' | 'file_too_large';

interface AttachmentDropzoneProps extends Omit<React.ComponentProps<'input'>, 'type' | 'onChange' | 'value'> {
  value?: File | null;
  onChange?: (file: File | null) => void;
  onUploadError?: (error: AttachmentDropzoneError | null) => void;
  maxSize?: number;
  className?: string;
  accept?: string;
  disabled?: boolean;
  children?: React.ReactNode;
}

function AttachmentDropzone({
  value,
  onChange,
  onUploadError,
  className,
  maxSize = 10 * 1024 * 1024, // Max Size: 10MB
  accept,
  disabled,
  children,
  ...props
}: AttachmentDropzoneProps) {
  const inputRef = React.useRef<HTMLInputElement>(null);
  const [isDragActive, setIsDragActive] = React.useState(false);
  const [error, setError] = React.useState(false);

  const handleUploadError = (err: AttachmentDropzoneError) => {
    onUploadError?.(err);
    setError(true);
    onChange?.(null);
  };

  const handleFiles = (files: FileList | null) => {
    if (files && files[0]) {
      setError(false);
      onUploadError?.(null);

      if (accept && !accept?.includes(files[0].name.split('.')[1])) {
        handleUploadError('invalid_type');
        return;
      }

      if (files[0].size > maxSize) {
        handleUploadError('file_too_large');
        return;
      }

      onChange?.(files[0]);
    }
  };

  const onDragOver = (e: React.DragEvent) => {
    e.preventDefault();
    if (disabled) return;
    setError(false);
    setIsDragActive(true);
  };

  const onDragLeave = (e: React.DragEvent) => {
    e.preventDefault();
    if (disabled) return;
    setError(false);
    setIsDragActive(false);
  };

  const onDrop = (e: React.DragEvent) => {
    e.preventDefault();
    if (disabled) return;
    setIsDragActive(false);
    handleFiles(e.dataTransfer.files);
  };

  React.useEffect(() => {
    if (!value) {
      if (inputRef.current) {
        inputRef.current.value = '';
      }
    }
  }, [value]);

  return (
    <label
      data-slot="attachment-dropzone"
      className={cn(
        'border-muted-foreground-400 text-muted-foreground flex w-full cursor-not-allowed flex-col items-center justify-center gap-2 rounded-md border border-dashed p-4',
        !disabled && focusVariants(),
        !disabled && inputVariants({ variant: 'input' }),
        !disabled && 'hover:bg-muted cursor-pointer',
        isDragActive && 'bg-muted',
        error && 'border-destructive hover:border-destructive',
        className,
      )}
      onDragOver={onDragOver}
      onDragLeave={onDragLeave}
      onDrop={onDrop}
    >
      {children}
      <input
        ref={inputRef}
        type="file"
        className="hidden"
        onChange={(e) => handleFiles(e.target.files)}
        accept={accept}
        disabled={disabled}
        {...props}
      />
    </label>
  );
}

export {
  Attachment,
  AttachmentGroup,
  AttachmentMedia,
  AttachmentContent,
  AttachmentTitle,
  AttachmentDescription,
  AttachmentActions,
  AttachmentAction,
  AttachmentTrigger,
  AttachmentDropzone,
  type AttachmentDropzoneError,
};
