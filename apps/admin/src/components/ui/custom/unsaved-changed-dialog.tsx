import { __ } from '@wordpress/i18n';

import { Button } from '../button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from './dialog';

interface UnsavedChangeDialogProps {
  open: boolean;
  onDiscard: () => void;
  onSave: () => void;
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
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{__('Unsaved Changes', 'yayloyalty')}</DialogTitle>
          {children}
        </DialogHeader>

        <DialogFooter>
          <Button variant="outline" onClick={onDiscard}>
            {__('Discard Changes', 'yayloyalty')}
          </Button>
          <Button onClick={onSave}>{__('Save Changes', 'yayloyalty')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
