import { ReactElement, ReactNode, useState } from 'react';
import { toast } from 'sonner';
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';

export type ArchiveDialogButton<A extends string> = { action: A; label: string; pendingLabel: string };

/**
 * The shell of an archive-or-delete confirmation: body lines with an optional `<link>` to the
 * Archived view, a destructive `primary` action and an optional `secondary` one on the left.
 * `perform` runs the write; the shell owns the pending state and the toasts.
 */
export function ArchiveOrDeleteDialog<A extends string>({
  title,
  lines,
  note,
  primary,
  secondary,
  perform,
  successMessage,
  failureMessage,
  onClose,
  onDone,
  onViewArchived,
}: {
  title: string;
  lines: string[];
  note?: ReactNode;
  primary: ArchiveDialogButton<A>;
  secondary?: ArchiveDialogButton<A>;
  perform: (action: A) => Promise<unknown>;
  successMessage: (action: A) => string;
  failureMessage: (action: A) => string;
  onClose: () => void;
  /** Runs after a successful write, once the dialog has closed. */
  onDone?: (action: A) => void;
  /** Omitted where there is no Archived view to jump to. */
  onViewArchived?: () => void;
}) {
  const [pendingAction, setPendingAction] = useState<A>();
  const busy = pendingAction !== undefined;

  async function run(action: A) {
    setPendingAction(action);

    try {
      await perform(action);
      toast.success(successMessage(action));
      onClose();
      onDone?.(action);
    } catch (error) {
      await toastApiError(error, failureMessage(action));
      setPendingAction(undefined);
    }
  }

  const label = (button: ArchiveDialogButton<A>) =>
    pendingAction === button.action ? button.pendingLabel : button.label;

  return (
    <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
      <DialogContent className="gap-0 p-0 sm:max-w-lg" showCloseButton={!busy}>
        <DialogHeader className="p-5 pr-12 pb-0">
          <DialogTitle>{title}</DialogTitle>
        </DialogHeader>

        <DialogDescription asChild>
          <div className="text-foreground flex flex-col gap-2 p-5">
            {lines.map((line) => (
              <p key={line}>{createInterpolateElement(line, { link: archivedLink(busy, onClose, onViewArchived) })}</p>
            ))}
            {note}
          </div>
        </DialogDescription>

        <DialogFooter className="bg-muted/40 border-t p-5 sm:items-center sm:justify-between">
          <div>
            {secondary && (
              <Button variant="outline" disabled={busy} onClick={() => run(secondary.action)}>
                {label(secondary)}
              </Button>
            )}
          </div>
          <div className="flex flex-col-reverse gap-2 sm:flex-row">
            <Button variant="outline" disabled={busy} onClick={onClose}>
              {__('Cancel', 'yayloyalty')}
            </Button>
            <Button variant="destructive" disabled={busy} onClick={() => run(primary.action)}>
              {label(primary)}
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** The <link> element in the body copy: jumps to the Archived view, or plain text where there is none. */
export function archivedLink(busy: boolean, onClose: () => void, onViewArchived?: () => void): ReactElement {
  if (!onViewArchived) {
    return <span />;
  }

  return (
    <Button
      variant="link"
      className="h-auto p-0 align-baseline underline"
      disabled={busy}
      onClick={() => {
        onClose();
        onViewArchived();
      }}
    />
  );
}
