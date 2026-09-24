import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { FormProvider, useForm } from 'react-hook-form';
import { toast } from 'sonner';

import { toastApiError } from '@/lib/api/api';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useDesignQuery, useResetDesignMutation, useSaveDesignMutation } from '@/lib/queries/design.queries';
import { designSchema } from '@/lib/schema/design.schema';
import { useRouteLeaveGuard } from '@/hooks/useRouteLeaveGuard';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import { ProGate } from '@/components/pro/ProGate';
import { DesignPanelSection, DesignTierSection, DesignTypographySection } from '@/pro';
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
import { DesignLauncherSection } from './DesignLauncherSection';
import { DesignStorefrontTextSection } from './DesignStorefrontTextSection';
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

  const { showDialog, confirmLeave, cancelLeave } = useRouteLeaveGuard(form.formState.isDirty);

  // form.reset() merges resetOptions, so without this override keepDirtyValues would also keep
  // every edited field over what the server just returned (its sanitised save, or the defaults).
  const replaceAllValues = { keepDirtyValues: false };

  // The Design page stays on its own route after a save, so there is no bypassNext()/navigate()
  // dance CampaignEditor needs — just reset before confirmLeave() so the guard doesn't re-trip.
  const handleSave = form.handleSubmit(async (values) => {
    try {
      const saved = await saveMutation.mutateAsync(values);
      form.reset(designToFormValues(saved), replaceAllValues);
      confirmLeave();
      toast.success(SAVE_SUCCESS_TOAST());
    } catch (error) {
      await toastApiError(error, SAVE_ERROR_FALLBACK());
    }
  });

  // No group, so every group comes back — including Pro ones a Lite build cannot see —
  // which is what makes a whole-form reset correct here; the storefront reset must not do this.
  async function handleResetWholeTab() {
    try {
      const returned = await resetMutation.mutateAsync(undefined);
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
        <form onSubmit={handleSave} className="flex flex-col gap-4">
          <DesignLauncherSection />
          <DesignStorefrontTextSection />
          <DesignVisibilitySection />

          {/* Ternary INSIDE ProGate's children, not <ProGate><X/></ProGate>: JSX evaluates
              children eagerly, so the latter drags the Pro subtree — sentinel included —
              into the Lite bundle. */}
          <ProGate>{IS_PRO_BUILD ? <DesignPanelSection /> : null}</ProGate>
          <ProGate>{IS_PRO_BUILD ? <DesignTypographySection /> : null}</ProGate>
          <ProGate>{IS_PRO_BUILD ? <DesignTierSection /> : null}</ProGate>

          <div className="flex items-center justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setResetOpen(true)}>
              {RESET_WHOLE_TAB_BUTTON_LABEL()}
            </Button>
            <Button type="submit" disabled={!form.formState.isDirty || saveMutation.isPending}>
              {SAVE_BUTTON_LABEL()}
            </Button>
          </div>
        </form>
      </FormProvider>

      <UnsavedChangeDialog
        open={showDialog}
        onDiscard={confirmLeave}
        onSave={handleSave}
        onOpenChange={(open) => {
          if (!open) {
            cancelLeave();
          }
        }}
      />

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
