'use client';

import { useCallback, type ComponentProps, type MouseEvent } from 'react';
import { type VariantProps } from 'class-variance-authority';
import { CheckIcon, CopyIcon } from 'lucide-react';
import { useClipboard, usePreviousDifferent } from 'rooks';

import { cn } from '@/lib/utils';
import { useUncontrolled } from '@/hooks/useUncontrolled';
import { buttonVariants } from './button';

type CopyButtonProps = Omit<ComponentProps<'button'>, 'children' | 'onCopy'> &
  VariantProps<typeof buttonVariants> & {
    content?: string;
    delay?: number;
    onCopy?: (content: string) => void;
    isCopied?: boolean;
    onCopyChange?: (isCopied: boolean) => void;
  };
function CopyButton({
  content,
  className,
  size = 'icon',
  variant = 'outline',
  delay = 3000,
  onClick,
  onCopy,
  isCopied: controlledIsCopied,
  onCopyChange,
  ...props
}: CopyButtonProps) {
  const { copy, text, isSupported } = useClipboard();

  const [isCopied, setIsCopied] = useUncontrolled({
    value: controlledIsCopied,
    defaultValue: text !== null && text === content,
    onChange: onCopyChange,
  });

  const previousIsCopied = usePreviousDifferent(isCopied);
  const isDirty = previousIsCopied !== null;

  const handleCopy = useCallback(
    async (e: MouseEvent<HTMLButtonElement>) => {
      e.preventDefault();
      onClick?.(e);

      if (isCopied || !isSupported || content === undefined) return;

      try {
        await copy(content);
        setIsCopied(true);
        onCopy?.(content);

        setTimeout(() => setIsCopied(false), delay);
      } catch (err) {
        console.error('Failed to copy:', err);
      }
    },
    [isCopied, isSupported, content, delay, onClick, onCopy, setIsCopied, copy],
  );

  return (
    <button
      data-slot="copy-button"
      className={cn(
        buttonVariants({ variant, size }),
        'transition-transform hover:scale-105 active:scale-95',
        className,
      )}
      onClick={handleCopy}
      {...props}
    >
      <span
        key={isCopied ? 'check' : 'copy'}
        data-slot="copy-button-icon"
        // Pops the new icon in once the state has changed, not on first render.
        className={cn(isDirty && 'animate-in zoom-in-0 duration-150')}
      >
        {isCopied ? <CheckIcon /> : <CopyIcon />}
      </span>
    </button>
  );
}

export { buttonVariants, CopyButton, type CopyButtonProps };
