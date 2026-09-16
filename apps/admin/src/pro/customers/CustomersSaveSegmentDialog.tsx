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

/**
 * The "Save current filters as segment" dialog — split out of
 * `CustomersSavedSegmentRow.tsx` to keep that file under this repo's
 * 200-line guideline. Owns nothing but the name input and the save
 * mutation; the picker/dropdown state lives in the parent.
 */
export function CustomersSaveSegmentDialog({
  open,
  onOpenChange,
  filters,
  onSaved,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The live filters to save — already built key-by-key by the caller, so `search` cannot leak in. */
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
      // Dialog stays open and the typed name is kept (including on a
      // duplicate-name 400), so the user can edit and retry — same pattern
      // as the bulk "Adjust points" dialog (customers-page.bulk-actions.ts).
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
