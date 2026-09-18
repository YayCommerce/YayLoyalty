import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';

import { adjustPointsSchema, type AdjustPointsInput } from '@/lib/schema/customer.schema';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';
import { Field, FieldContent, FieldError, FieldLabel } from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot } from '@/components/ui/number-input';
import { Textarea } from '@/components/ui/textarea';

/** The bulk adjust-points form. Plain useState, not react-hook-form: two fields, no dependent validation. */
export function CustomersAdjustPointsDialog({
  open,
  onOpenChange,
  count,
  isSubmitting,
  onSubmit,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** How many customers this bulk adjustment applies to. */
  count: number;
  isSubmitting: boolean;
  onSubmit: (input: AdjustPointsInput) => void;
}) {
  const [points, setPoints] = useState(0);
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);

  function handleOpenChange(next: boolean) {
    if (!next) {
      // A stale value carrying into the next bulk action would be a footgun.
      setPoints(0);
      setNote('');
      setError(null);
    }
    onOpenChange(next);
  }

  function handleSubmit() {
    // adjustPointsSchema stays a pure mirror of the wire shape; non-zero is this form's rule.
    if (points === 0) {
      setError(__('Enter a non-zero number of points.', 'yayloyalty'));
      return;
    }

    const result = adjustPointsSchema.safeParse({ points, note });
    if (!result.success) {
      setError(result.error.issues[0]?.message ?? __('Invalid input.', 'yayloyalty'));
      return;
    }

    setError(null);
    onSubmit(result.data);
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{__('Adjust points', 'yayloyalty')}</DialogTitle>
          <DialogDescription>
            {sprintf(
              _n(
                'Apply a manual adjustment to %d customer.',
                'Apply a manual adjustment to %d customers.',
                count,
                'yayloyalty',
              ),
              count,
            )}
          </DialogDescription>
        </DialogHeader>

        <Field>
          <FieldLabel htmlFor="adjust-points-value">{__('Points', 'yayloyalty')}</FieldLabel>
          <FieldContent>
            <NumberInputRoot value={points} onValueChange={setPoints}>
              <NumberInputInput id="adjust-points-value" />
            </NumberInputRoot>
            <p className="text-muted-foreground text-xs">
              {__('Positive to grant, negative to deduct.', 'yayloyalty')}
            </p>
          </FieldContent>
        </Field>

        <Field>
          <FieldLabel htmlFor="adjust-points-note">{__('Note', 'yayloyalty')}</FieldLabel>
          <FieldContent>
            <Textarea
              id="adjust-points-note"
              value={note}
              onChange={(event) => setNote(event.target.value)}
              placeholder={__('Reason for this adjustment (shown in history)', 'yayloyalty')}
            />
          </FieldContent>
        </Field>

        {error && <FieldError>{error}</FieldError>}

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={isSubmitting}>
            {__('Cancel', 'yayloyalty')}
          </Button>
          <Button onClick={handleSubmit} disabled={isSubmitting}>
            {isSubmitting ? __('Applying…', 'yayloyalty') : __('Apply', 'yayloyalty')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
