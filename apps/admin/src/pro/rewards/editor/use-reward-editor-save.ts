import { useRef } from 'react';
import { UseFormReturn } from 'react-hook-form';
import { useNavigate } from 'react-router';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { useRouteLeaveGuard } from '@/hooks/useRouteLeaveGuard';
import { RewardFormValues, RewardSaveIntent } from './reward-editor.form';

/**
 * One onSubmit for Save, Save draft and the leave dialog's Save, then back
 * to the list. `save` writes the values and says whether it succeeded; it
 * must reset the form's dirty baseline before resolving, or the redirect
 * re-trips the leave guard.
 */
export function useRewardEditorSave(
  form: UseFormReturn<RewardFormValues>,
  save: (values: RewardFormValues, intent: RewardSaveIntent) => Promise<boolean>,
) {
  const navigate = useNavigate();
  const { showDialog, confirmLeave, cancelLeave, bypassNext } = useRouteLeaveGuard(form.formState.isDirty);

  // Fallbacks for SubmitEvent.submitter, missing in Safari < 15.4 and on the dialog's Save, which has no submit event.
  const isDraftIntentRef = useRef(false);
  const isDialogSaveRef = useRef(false);

  /* eslint-disable react-hooks/refs -- handleSubmit only invokes these from a real submit, never
     during render; the rule cannot see through its deferred call. */
  const handleSave = form.handleSubmit(
    async (values, event) => {
      const submitter = (event?.nativeEvent as SubmitEvent | undefined)?.submitter as HTMLElement | null | undefined;
      const isDraft = submitter ? submitter.dataset.intent === 'draft' : isDraftIntentRef.current;
      const savingFromDialog = isDialogSaveRef.current;
      isDraftIntentRef.current = false;
      isDialogSaveRef.current = false;

      if (!(await save(values, isDraft ? 'draft' : 'save'))) {
        return;
      }

      confirmLeave();

      // A dialog save already proceeds to the blocked destination; navigating here would override it.
      if (!savingFromDialog) {
        // isDirty:false from reset() may not have re-rendered yet; skip the blocker for this one navigation.
        bypassNext();
        navigate('/rewards');
      }
    },
    () => {
      isDraftIntentRef.current = false;
      isDialogSaveRef.current = false;
      // The buttons sit in the side area, often far from the field that failed.
      toast.error(__('Please fix the highlighted fields before saving.', 'yayloyalty'));
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
