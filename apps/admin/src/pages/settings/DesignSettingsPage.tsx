import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { FormProvider, useForm } from 'react-hook-form';
import { toast } from 'sonner';

import { toastApiError } from '@/lib/api/api';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useDesignQuery, useResetDesignMutation, useSaveDesignMutation } from '@/lib/queries/design.queries';
import { designSchema } from '@/lib/schema/design.schema';
import { useEditorSave } from '@/hooks/useEditorSave';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { ProGate } from '@/components/pro/ProGate';
import { DesignPanelSection, DesignTypographySection } from '@/pro';
import {
  CANCEL_LABEL,
  RESET_CONFIRM_LABEL,
  RESET_ERROR_FALLBACK,
  RESET_WHOLE_TAB_BUTTON_LABEL,
  RESET_WHOLE_TAB_CONFIRM_DESCRIPTION,
  RESET_WHOLE_TAB_CONFIRM_TITLE,
  RESET_WHOLE_TAB_SUCCESS_TOAST,
  SAVE_BUTTON_LABEL,
  SAVE_ERROR_FALLBACK,
  SAVE_SUCCESS_TOAST,
} from './design-settings.copy';
import { DesignFormValues, designToFormValues } from './design-settings.form';
import { DesignLauncherPreview } from './DesignLauncherPreview';
import { DesignLauncherSection } from './DesignLauncherSection';
import { DesignVisibilitySection } from './DesignVisibilitySection';

/**
 * The Design tab: form wiring, layout and save/reset/leave-guard state only.
 * Every field lives in its own section, which reads and writes through
 * `useFormContext` and takes no props.
 */
export default function DesignSettingsPage() {
  const designQuery = useDesignQuery();
  const saveMutation = useSaveDesignMutation();
  const resetMutation = useResetDesignMutation();
  const [resetOpen, setResetOpen] = useState(false);

  // `values` (not defaultValues) + keepDirtyValues: a refetch re-seeds the form without
  // clobbering a field mid-edit, same reasoning as CampaignEditor.
  const form = useForm<DesignFormValues>({
    resolver: zodResolver(designSchema),
    values: designToFormValues(designQuery.data),
    resetOptions: { keepDirtyValues: true },
  });

  // form.reset() merges resetOptions, so without this override keepDirtyValues would also keep
  // every edited field over what the server just returned (its sanitised save, or the defaults).
  const replaceAllValues = { keepDirtyValues: false };

  // Reset before resolving so the leave guard does not re-trip. listPath null: the page stays on its route.
  const { handleSave, leaveDialogProps } = useEditorSave(
    form,
    async (values) => {
      try {
        const saved = await saveMutation.mutateAsync(values);
        form.reset(designToFormValues(saved), replaceAllValues);
        toast.success(SAVE_SUCCESS_TOAST());
        return true;
      } catch (error) {
        await toastApiError(error, SAVE_ERROR_FALLBACK());
        return false;
      }
    },
    { listPath: null },
  );

  // Every group comes back, including Pro ones a Lite build cannot see, so the whole form is replaced.
  async function handleResetWholeTab() {
    try {
      const returned = await resetMutation.mutateAsync();
      form.reset(designToFormValues(returned), replaceAllValues);
      toast.success(RESET_WHOLE_TAB_SUCCESS_TOAST());
      setResetOpen(false);
    } catch (error) {
      await toastApiError(error, RESET_ERROR_FALLBACK());
    }
  }

  return (
    <>
      <FormProvider {...form}>
        <form onSubmit={handleSave} className="flex flex-col gap-4 xl:flex-row xl:items-start">
          <div className="flex min-w-0 flex-1 flex-col gap-4">
            <DesignLauncherSection />
            <DesignVisibilitySection />

            {/* IS_PRO_BUILD inside ProGate: ProGate alone would still ship the Pro sections in Lite
              (see pro/index.ts). */}
            <ProGate>{IS_PRO_BUILD ? <DesignPanelSection /> : null}</ProGate>
            <ProGate>{IS_PRO_BUILD ? <DesignTypographySection /> : null}</ProGate>

            <div className="flex items-center justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setResetOpen(true)}>
                {RESET_WHOLE_TAB_BUTTON_LABEL()}
              </Button>
              <Button type="submit" disabled={!form.formState.isDirty || saveMutation.isPending}>
                {SAVE_BUTTON_LABEL()}
              </Button>
            </div>
          </div>

          <div className="w-full xl:sticky xl:top-6 xl:w-110 xl:shrink-0">
            <DesignLauncherPreview />
          </div>
        </form>
      </FormProvider>

      <UnsavedChangeDialog {...leaveDialogProps} />

      <ConfirmDialog
        open={resetOpen}
        onOpenChange={setResetOpen}
        title={RESET_WHOLE_TAB_CONFIRM_TITLE()}
        description={RESET_WHOLE_TAB_CONFIRM_DESCRIPTION()}
        confirmLabel={RESET_CONFIRM_LABEL()}
        cancelLabel={CANCEL_LABEL()}
        variant="destructive"
        isLoading={resetMutation.isPending}
        onConfirm={handleResetWholeTab}
      />
    </>
  );
}
