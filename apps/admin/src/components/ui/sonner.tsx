import clsx from 'clsx';
import { Check, X } from 'lucide-react';
import { Toaster as Sonner, toast, ToasterProps } from 'sonner';

import { useTheme } from './theme-provider';

const Toaster = ({ ...props }: ToasterProps) => {
  const { theme = 'system' } = useTheme();

  return (
    <Sonner
      position="top-center"
      theme={theme}
      className={clsx(
        'yay-toaster group flex flex-col data-[x-position=center]:items-center data-[x-position=left]:items-start data-[x-position=right]:items-end',
      )}
      icons={{
        success: (
          <ToastCircleIcon className="bg-success text-success-foreground">
            <Check className="size-2.5 stroke-3" style={{ translate: '0.5px 0.5px' }} />
          </ToastCircleIcon>
        ),
        error: (
          <ToastCircleIcon className="bg-destructive text-destructive-foreground">
            <X className="size-2.5 stroke-3" style={{ translate: '0.5px 0.5px' }} />
          </ToastCircleIcon>
        ),
      }}
      style={
        {
          '--normal-bg': 'var(--popover)',
          '--normal-text': 'var(--popover-foreground)',
          '--normal-border': 'var(--border)',
          '--border-radius': 'var(--radius-md)',
        } as React.CSSProperties
      }
      toastOptions={{
        classNames: {
          toast: 'yay-toast sm:not-has-[.yay-toast-action]:w-max sm:has-[.yay-toast-action]:w-(--width) mx-auto',
          actionButton: 'yay-toast-action',
        },
      }}
      {...props}
    />
  );
};

function ToastCircleIcon({ children, className }: { children: React.ReactNode; className?: string }) {
  return (
    <span className={clsx('inline-flex size-4 items-center justify-center rounded-full', className)}>{children}</span>
  );
}

export { Toaster, toast };
