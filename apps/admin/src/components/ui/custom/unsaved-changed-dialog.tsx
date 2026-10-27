import { useState } from 'react';
import { __ } from '@wordpress/i18n';

import { Button, LoadingButton } from '../button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from './dialog';

interface UnsavedChangeDialogProps {
  open: boolean;
  onDiscard: () => void;
  /** The Save button shows a spinner until a returned promise settles. */
  onSave: () => void | Promise<unknown>;
  onOpenChange: (open: boolean) => void;
  children?: React.ReactNode;
}

export function UnsavedChangeDialog({
  open,
  onDiscard,
  onSave,
  onOpenChange,
  children = (
    <DialogDescription>
      {__('You have unsaved changes. Do you want to save them before leaving?', 'yayloyalty')}
    </DialogDescription>
  ),
}: UnsavedChangeDialogProps) {
  const [isSaving, setIsSaving] = useState(false);

  const save = async () => {
    setIsSaving(true);

    try {
      await onSave();
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(next) => !isSaving && onOpenChange(next)}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{__('Unsaved Changes', 'yayloyalty')}</DialogTitle>
          {children}
        </DialogHeader>

        <DialogFooter>
          <Button variant="outline" disabled={isSaving} onClick={onDiscard}>
            {__('Discard Changes', 'yayloyalty')}
          </Button>
          <LoadingButton loading={isSaving} disabled={isSaving} onClick={save}>
            {__('Save Changes', 'yayloyalty')}
          </LoadingButton>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
