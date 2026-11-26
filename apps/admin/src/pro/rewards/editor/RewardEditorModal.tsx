import type { ReactNode } from 'react';
import { useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/custom/dialog';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Skeleton } from '@/components/ui/skeleton';

const REWARD_MODAL_DESCRIPTION = () => __('Set the discount, points cost, and who can redeem it.', 'yayloyalty');

/**
 * The add/edit reward modal over the Rewards list. Closing goes back to /rewards, so the route leave guard
 * still asks about unsaved changes. Each entry renders this once at its root and swaps only `children`
 * (header, scrolling body, then optionally a footer), so loading → form never remounts the dialog.
 */
export function RewardEditorModal({ children }: { children: ReactNode }) {
  const navigate = useNavigate();

  return (
    <Dialog open onOpenChange={(open) => !open && navigate('/rewards')}>
      <DialogContent className="max-h-[90vh] grid-rows-[auto_minmax(0,1fr)_auto] gap-0 overflow-hidden p-0 sm:max-w-2xl">
        {children}
      </DialogContent>
    </Dialog>
  );
}

export function RewardEditorModalHeader({ title }: { title: ReactNode }) {
  return (
    <DialogHeader className="gap-1 border-b px-6 py-4">
      <DialogTitle>{title}</DialogTitle>
      <DialogDescription>{REWARD_MODAL_DESCRIPTION()}</DialogDescription>
    </DialogHeader>
  );
}

/** The header and body while the reward loads, or when it could not be loaded. */
export function RewardEditorModalState({
  title,
  error,
  onRetry,
}: {
  title: string;
  /** Absent while loading. */
  error?: string;
  onRetry?: () => void;
}) {
  return (
    <>
      <RewardEditorModalHeader title={title} />
      <div className="flex flex-col gap-5 overflow-y-auto px-6 py-5">
        {error ? (
          <ErrorState message={error} onRetry={onRetry} />
        ) : (
          [0, 1, 2].map((key) => (
            <div key={key} className="flex flex-col gap-3">
              <Skeleton className="h-5 w-32" />
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-2/3" />
            </div>
          ))
        )}
      </div>
    </>
  );
}
