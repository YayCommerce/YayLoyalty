import { Component, lazy, ReactNode, Suspense, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/custom/dialog';

// Lazy load the heavy emoji picker component (~600KB saved from initial bundle)
const LazyEmojiPickerContent = lazy(() => import('./emoji-picker-content'));

// Simple error boundary for chunk load failures
interface ErrorBoundaryState {
  hasError: boolean;
}

class EmojiPickerErrorBoundary extends Component<
  { children: ReactNode; onRetry: () => void },
  ErrorBoundaryState
> {
  state: ErrorBoundaryState = { hasError: false };

  static getDerivedStateFromError(): ErrorBoundaryState {
    return { hasError: true };
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="flex h-[400px] w-[352px] flex-col items-center justify-center gap-3 p-4 text-center">
          <p className="text-muted-foreground text-sm">Failed to load emoji picker</p>
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              this.setState({ hasError: false });
              this.props.onRetry();
            }}
          >
            Try again
          </Button>
        </div>
      );
    }
    return this.props.children;
  }
}

interface EmojiPickerProps {
  value: string;
  onChange: (emoji: string) => void;
  disabled?: boolean;
}

export function EmojiPicker({ value, onChange, disabled }: EmojiPickerProps) {
  const [open, setOpen] = useState(false);
  const [retryKey, setRetryKey] = useState(0);

  const handleSelect = (emoji: string) => {
    onChange(emoji);
    setOpen(false);
  };

  const handleRetry = () => {
    setRetryKey((k) => k + 1);
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className="h-10 w-14 text-xl"
          disabled={disabled}
          type="button"
        >
          {value || '?'}
        </Button>
      </DialogTrigger>
      <DialogContent className="w-auto max-w-none gap-0 overflow-hidden p-0">
        <DialogHeader className="sr-only">
          <DialogTitle>Select Emoji</DialogTitle>
        </DialogHeader>
        {open && (
          <EmojiPickerErrorBoundary onRetry={handleRetry}>
            <Suspense
              fallback={
                <div className="flex h-[400px] w-[352px] items-center justify-center">
                  <div className="border-primary h-6 w-6 animate-spin rounded-full border-2 border-t-transparent" />
                </div>
              }
            >
              <LazyEmojiPickerContent key={retryKey} onEmojiSelect={handleSelect} />
            </Suspense>
          </EmojiPickerErrorBoundary>
        )}
      </DialogContent>
    </Dialog>
  );
}
