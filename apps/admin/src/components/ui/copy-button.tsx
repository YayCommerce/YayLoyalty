'use client';

import { useCallback, type MouseEvent } from 'react';
import { type VariantProps } from 'class-variance-authority';
import { CheckIcon, CopyIcon } from 'lucide-react';
import { AnimatePresence, HTMLMotionProps } from 'motion/react';
import * as m from 'motion/react-m';
import { useClipboard, usePreviousDifferent } from 'rooks';

import { cn } from '@/lib/utils';
import { useUncontrolled } from '@/hooks/useUncontrolled';
import { buttonVariants } from './button';

type CopyButtonProps = Omit<HTMLMotionProps<'button'>, 'children' | 'onCopy'> &
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
    <m.button
      data-slot="copy-button"
      whileHover={{ scale: 1.05 }}
      whileTap={{ scale: 0.95 }}
      className={cn(buttonVariants({ variant, size }), className)}
      onClick={handleCopy}
      {...props}
    >
      <AnimatePresence mode="wait">
        <m.span
          key={isCopied ? 'check' : 'copy'}
          data-slot="copy-button-icon"
          initial={{ scale: isDirty ? 0 : 1 }} // start scale animation when isDirty
          animate={{ scale: 1 }}
          exit={{ scale: 0 }}
          transition={{ duration: 0.15 }}
        >
          {isCopied ? <CheckIcon /> : <CopyIcon />}
        </m.span>
      </AnimatePresence>
    </m.button>
  );
}

export { buttonVariants, CopyButton, type CopyButtonProps };
