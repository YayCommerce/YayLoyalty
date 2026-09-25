import { useRef } from 'react';
import { UseFormReturn } from 'react-hook-form';
import { useNavigate } from 'react-router';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { useRouteLeaveGuard } from '@/hooks/useRouteLeaveGuard';
import { CampaignFormValues } from './campaign-editor.form';

/**
 * The save flow both editor forms share: one onSubmit for both save buttons
 * and the leave dialog's Save, then back to the list. `save` writes the
 * values (as a draft when asked) and says whether it succeeded; it must
 * reset the form's dirty baseline itself before resolving, or the redirect
 * re-trips the leave guard.
 */
export function useCampaignEditorSave(
  form: UseFormReturn<CampaignFormValues>,
  save: (values: CampaignFormValues, isDraft: boolean) => Promise<boolean>,
) {
  const navigate = useNavigate();
  const { showDialog, confirmLeave, cancelLeave, bypassNext } = useRouteLeaveGuard(form.formState.isDirty);

  // Fallbacks for handleSave, reset at the top of each submit: SubmitEvent.submitter is missing
  // in Safari < 15.4, and the UnsavedChangeDialog save has no submit event at all.
  const isDraftIntentRef = useRef(false);
  const isDialogSaveRef = useRef(false);

  // The submitter says which button was pressed, with the ref as fallback.
  /* eslint-disable react-hooks/refs -- handleSubmit only invokes these from a real submit, never
     during render; the rule cannot see through its deferred call. */
  const handleSave = form.handleSubmit(
    async (values, event) => {
      const submitter = (event?.nativeEvent as SubmitEvent | undefined)?.submitter as HTMLElement | null | undefined;
      const isDraft = submitter ? submitter.dataset.intent === 'draft' : isDraftIntentRef.current;
      const savingFromDialog = isDialogSaveRef.current;
      isDraftIntentRef.current = false;
      isDialogSaveRef.current = false;

      if (!(await save(values, isDraft))) {
        return;
      }

      // confirmLeave is a no-op outside a blocked navigation. Disarm the guard, then navigate.
      confirmLeave();

      // A dialog save already proceeds to the originally blocked destination; navigating here
      // would override it.
      if (!savingFromDialog) {
        // The reset()-driven isDirty:false may not have re-rendered yet — bypass this one
        // navigation so the blocker doesn't re-trip on its own stale closure.
        bypassNext();
        navigate('/campaigns');
      }
    },
    (errors) => {
      // ConditionRow shows each error inline; the toast makes a blocked save impossible to miss.
      if (errors.conditions) {
        toast.error(__('Please fix the highlighted conditions before saving.', 'yayloyalty'));
      }
    },
  );
  /* eslint-enable react-hooks/refs */

  return {
    handleSave,
    bypassNext,
    markDraftIntent: () => {
      isDraftIntentRef.current = true;
    },
    /** Spread onto UnsavedChangeDialog. */
    leaveDialogProps: {
      open: showDialog,
      onOpenChange: (open: boolean) => {
        if (!open) {
          cancelLeave();
        }
      },
      onDiscard: confirmLeave,
      onSave: () => {
        isDialogSaveRef.current = true;
        return handleSave();
      },
    },
  };
}
