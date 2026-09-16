import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { RowFilters } from '@/pages/customers/customers-page.filter-rows';
import { ALL_SEGMENTS_VALUE, useSavedSegmentPicker } from './customers-saved-segment-row.state';
import { CustomersSaveSegmentDialog } from './CustomersSaveSegmentDialog';

/**
 * Pro-only saved-segment picker shown above the Customers toolbar.
 *
 * DELETED FROM THE LITE BUILD along with the rest of `src/pro/` — see
 * `CustomersFilterBar.tsx`'s `IS_PRO_BUILD` early return, which is what
 * makes this reachable at all.
 *
 * Pure rendering only. All state, derived booleans (`showSaveButton`,
 * `showRemoveButton`) and mutations live in
 * `customers-saved-segment-row.state.ts` — see that file for the
 * select/save/remove design notes, including why the Save and Remove
 * buttons occupy the same slot and are mutually exclusive.
 */
export function CustomersSavedSegmentRow({
  disabled,
  filters,
  onFiltersChange,
}: {
  disabled?: boolean;
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
}) {
  const {
    segments,
    selected,
    current,
    showSaveButton,
    showRemoveButton,
    dialogOpen,
    setDialogOpen,
    onSelect,
    onSaved,
    onRemove,
    isRemoving,
  } = useSavedSegmentPicker({ filters, onFiltersChange });
  const [confirmRemoveOpen, setConfirmRemoveOpen] = useState(false);

  function handleConfirmedRemove() {
    // Closes right away rather than waiting for the request: the "Remove
    // segment" button itself already shows the pending state
    // (disabled + "Removing…"), so the confirm dialog doesn't need to
    // duplicate that — its only job was getting a yes/no.
    setConfirmRemoveOpen(false);
    onRemove();
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-muted-foreground text-xs">{__('Saved segment', 'yayloyalty')}</span>
      {/* Locked during a remove: switching segments mid-request would let
          the pending remove's onSuccess reset `selected` back to "All
          customers" right over whatever the user just picked instead. */}
      <Select value={selected} onValueChange={onSelect} disabled={disabled || isRemoving}>
        <SelectTrigger size="sm">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={ALL_SEGMENTS_VALUE}>{__('All customers', 'yayloyalty')}</SelectItem>
          {segments.map((segment) => (
            <SelectItem key={segment.name} value={segment.name}>
              {segment.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      {showSaveButton && (
        <Can cap={CAP.MANAGE_POINTS} disable>
          <Button variant="link" size="sm" disabled={disabled} onClick={() => setDialogOpen(true)}>
            {__('Save current filters as segment', 'yayloyalty')}
          </Button>
        </Can>
      )}

      {showRemoveButton && (
        <Can cap={CAP.MANAGE_POINTS} disable>
          <Button
            variant="link"
            size="sm"
            className="text-destructive hover:text-destructive-accent"
            disabled={disabled || isRemoving}
            onClick={() => setConfirmRemoveOpen(true)}
          >
            {isRemoving ? __('Removing…', 'yayloyalty') : __('Remove segment', 'yayloyalty')}
          </Button>
        </Can>
      )}

      <Dialog open={confirmRemoveOpen} onOpenChange={setConfirmRemoveOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{__('Remove this segment?', 'yayloyalty')}</DialogTitle>
            <DialogDescription>
              {sprintf(
                /* translators: %s: the saved segment's name. */
                __(
                  '“%s” will be permanently removed from your saved segments. This does not change the filters or customer list currently shown.',
                  'yayloyalty',
                ),
                selected,
              )}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmRemoveOpen(false)}>
              {__('Cancel', 'yayloyalty')}
            </Button>
            <Button variant="destructive" onClick={handleConfirmedRemove}>
              {__('Remove', 'yayloyalty')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <CustomersSaveSegmentDialog open={dialogOpen} onOpenChange={setDialogOpen} filters={current} onSaved={onSaved} />
    </div>
  );
}
