import { useRef } from 'react';
import { FieldErrors, FieldValues, UseFormReturn } from 'react-hook-form';

import { useNavigate } from '@/lib/router';
import { useRouteLeaveGuard } from '@/hooks/useRouteLeaveGuard';

export type EditorSaveIntent = 'save' | 'draft';

/**
 * The save flow every definition editor and settings page shares: one onSubmit for Save, Save
 * draft and the leave dialog's Save, then back to `listPath`, or nowhere when
 * it is null. `save` resolves false on failure, true on success, or a path to
 * go to instead. It must reset the form's dirty baseline before resolving, or
 * the redirect re-trips the leave guard.
 */
export function useEditorSave<T extends FieldValues>(
  form: UseFormReturn<T>,
  save: (values: T, intent: EditorSaveIntent) => Promise<boolean | string>,
  { listPath, onInvalid }: { listPath: string | null; onInvalid?: (errors: FieldErrors<T>) => void },
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

      const result = await save(values, isDraft ? 'draft' : 'save');

      if (!result) {
        return;
      }

      confirmLeave();

      const nextPath = typeof result === 'string' ? result : listPath;

      // A dialog save already proceeds to the blocked destination; navigating here would override it.
      if (!savingFromDialog && nextPath) {
        // isDirty:false from reset() may not have re-rendered yet; skip the blocker for this one navigation.
        bypassNext();
        navigate(nextPath, { replace: typeof result === 'string' });
      }
    },
    (errors) => {
      isDraftIntentRef.current = false;
      isDialogSaveRef.current = false;
      onInvalid?.(errors);
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
