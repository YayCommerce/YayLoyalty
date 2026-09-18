import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { getApiErrorMessage } from '@/lib/api/api';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';
import { Field, FieldContent, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { saveSegment, type SavedSegment } from './customer-segments.api';

/** The "Save current filters as segment" dialog: the name input and the save mutation only. */
export function CustomersSaveSegmentDialog({
  open,
  onOpenChange,
  filters,
  onSaved,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The filters to save, built key-by-key by the caller so `search` cannot leak in. */
  filters: SavedSegment['filters'];
  /** Called with the full, server-confirmed list once a save succeeds. */
  onSaved: (list: SavedSegment[]) => void;
}) {
  const [name, setName] = useState('');

  const save = useMutation({
    mutationFn: () => saveSegment(name.trim(), filters),
    onSuccess: (list) => {
      onSaved(list);
      setName('');
      onOpenChange(false);
    },
    onError: async (err) => {
      // The dialog stays open with the typed name so the user can edit and retry.
      toast.error(await getApiErrorMessage(err, __('Could not save the segment.', 'yayloyalty')));
    },
  });

  function handleOpenChange(next: boolean) {
    if (!next) {
      setName('');
    }
    onOpenChange(next);
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{__('Save current filters as segment', 'yayloyalty')}</DialogTitle>
          <DialogDescription>
            {__('Balance range, Expiring soon and Tier are saved. Search is not.', 'yayloyalty')}
          </DialogDescription>
        </DialogHeader>

        <Field>
          <FieldLabel htmlFor="save-segment-name">{__('Segment name', 'yayloyalty')}</FieldLabel>
          <FieldContent>
            <Input
              id="save-segment-name"
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder={__('e.g. High balance', 'yayloyalty')}
            />
          </FieldContent>
        </Field>

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={save.isPending}>
            {__('Cancel', 'yayloyalty')}
          </Button>
          <Button onClick={() => save.mutate()} disabled={name.trim() === '' || save.isPending}>
            {save.isPending ? __('Saving…', 'yayloyalty') : __('Save', 'yayloyalty')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
