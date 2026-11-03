import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { Button, buttonVariants } from '@/components/ui/button';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/custom/alert-dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { CustomerListFilters } from '@/pages/customers/customers-page.filters';
import { ALL_SEGMENTS_VALUE, useSavedSegmentPicker } from './customers-saved-segment-picker';
import { CustomersSaveSegmentDialog } from './CustomersSaveSegmentDialog';

/** Saved-segment picker above the Customers toolbar. Rendering only; state lives in customers-saved-segment-picker.ts. */
export function CustomersSavedSegmentRow({
  disabled,
  filters,
  onFiltersChange,
}: {
  disabled?: boolean;
  filters: CustomerListFilters;
  onFiltersChange: (patch: Partial<CustomerListFilters>) => void;
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

  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-muted-foreground text-xs">{__('Saved segment', 'yayloyalty')}</span>
      {/* Locked during a remove: the pending remove's onSuccess resets `selected` to "All
          customers", over whatever the user picked meanwhile. */}
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

      {/* AlertDialog, not Dialog: a destructive confirmation gets role="alertdialog". Action and
          Cancel close it on their own, even with a controlled `open`, so `onRemove` only fires the
          mutation. */}
      <AlertDialog open={confirmRemoveOpen} onOpenChange={setConfirmRemoveOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{__('Remove this segment?', 'yayloyalty')}</AlertDialogTitle>
            <AlertDialogDescription>
              {sprintf(
                /* translators: %s: the saved segment's name. */
                __(
                  '“%s” will be permanently removed from your saved segments. This does not change the filters or customer list currently shown.',
                  'yayloyalty',
                ),
                selected,
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{__('Cancel', 'yayloyalty')}</AlertDialogCancel>
            <AlertDialogAction className={buttonVariants({ variant: 'destructive' })} onClick={onRemove}>
              {__('Remove', 'yayloyalty')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <CustomersSaveSegmentDialog open={dialogOpen} onOpenChange={setDialogOpen} filters={current} onSaved={onSaved} />
    </div>
  );
}
