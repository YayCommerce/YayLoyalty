import { useState, type ReactNode } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { FormProvider, useForm, type FieldPath } from 'react-hook-form';
import { useNavigate } from 'react-router';
import { toast } from 'sonner';

import { toastApiError } from '@/lib/api/api';
import { useCompleteSetupMutation, useSaveSetupDraftMutation, useSetupQuery } from '@/lib/queries/setup.queries';
import { SETUP_LAST_STEP, setupDraftSchema, type SetupDraft } from '@/lib/schema/setup.schema';
import { Button, LoadingButton } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Skeleton } from '@/components/ui/skeleton';
import {
  CONTINUE_LABEL,
  LOAD_ERROR,
  SAVE_ERROR_FALLBACK,
  SAVE_LATER_LABEL,
  SKIP_FIRST_LABEL,
  SKIP_LABEL,
  TURN_ON_ERROR_FALLBACK,
  TURN_ON_LABEL,
  TURN_ON_SUCCESS_TOAST,
} from './setup-wizard.copy';
import { draftFromLive } from './setup-wizard.draft';
import { SetupStepEarn } from './SetupStepEarn';
import { SetupStepLook } from './SetupStepLook';
import { SetupStepPointsName } from './SetupStepPointsName';
import { SetupStepReview } from './SetupStepReview';
import { SetupStepSpend } from './SetupStepSpend';
import { SetupWizardLayout } from './SetupWizardLayout';

/** Each step's body and the fields its Continue validates; steps read and write through the form context. */
const SETUP_STEPS: Record<
  number,
  { fields: FieldPath<SetupDraft>[]; render: (onEdit: (step: number) => void) => ReactNode }
> = {
  1: { fields: ['points_label_singular', 'points_label_plural'], render: () => <SetupStepPointsName /> },
  2: { fields: ['earn', 'signup', 'review'], render: () => <SetupStepEarn /> },
  3: { fields: ['reward'], render: () => <SetupStepSpend /> },
  4: { fields: ['launcher'], render: () => <SetupStepLook /> },
  5: { fields: [], render: (onEdit) => <SetupStepReview onEdit={onEdit} /> },
};

/** The setup route: loads the saved state, then hands the form to the wizard. */
export default function SetupWizardPage() {
  const query = useSetupQuery();

  if (query.isError) {
    return <ErrorState message={LOAD_ERROR()} onRetry={() => query.refetch()} />;
  }

  if (!query.data) {
    return <Skeleton className="m-6 h-96" />;
  }

  return (
    <SetupWizard initialStep={query.data.step} initialDraft={query.data.draft ?? draftFromLive(query.data.live)} />
  );
}

/**
 * Navigation and saving. Continue validates only the current step, saves the draft with the next step,
 * then moves; Skip saves and jumps to the review; Exit saves where the merchant is and leaves.
 * Nothing live changes until "Turn on my program".
 */
function SetupWizard({ initialStep, initialDraft }: { initialStep: number; initialDraft: SetupDraft }) {
  const navigate = useNavigate();
  const saveDraft = useSaveSetupDraftMutation();
  const complete = useCompleteSetupMutation();
  const [step, setStep] = useState(initialStep);
  // Set by step 5's Edit links: that step's Continue goes back to the review instead of onward.
  const [returnToReview, setReturnToReview] = useState(false);
  const form = useForm<SetupDraft>({ resolver: zodResolver(setupDraftSchema), defaultValues: initialDraft });
  const isBusy = saveDraft.isPending || complete.isPending;

  async function saveAt(nextStep: number): Promise<boolean> {
    try {
      await saveDraft.mutateAsync({ step: nextStep, draft: setupDraftSchema.parse(form.getValues()) });
      return true;
    } catch (error) {
      await toastApiError(error, SAVE_ERROR_FALLBACK());
      return false;
    }
  }

  async function goTo(nextStep: number) {
    if ((await form.trigger(SETUP_STEPS[step].fields)) && (await form.trigger()) && (await saveAt(nextStep))) {
      setStep(nextStep);
      setReturnToReview(false);
    }
  }

  async function exit() {
    // An invalid answer is not saved; the merchant comes back to the last valid draft.
    if ((await form.trigger()) && !(await saveAt(step))) {
      return;
    }
    navigate('/dashboard');
  }

  async function turnOn() {
    try {
      await complete.mutateAsync(setupDraftSchema.parse(form.getValues()));
      toast.success(TURN_ON_SUCCESS_TOAST());
    } catch (error) {
      await toastApiError(error, TURN_ON_ERROR_FALLBACK());
    }
    // Live, or already turned on elsewhere (409): either way setup is over for this visit.
    navigate('/dashboard');
  }

  const isReview = step === SETUP_LAST_STEP;

  return (
    <FormProvider {...form}>
      <SetupWizardLayout
        step={step}
        isBusy={isBusy}
        onExit={exit}
        onBack={step > 1 ? () => setStep(step - 1) : null}
        skip={
          isReview ? (
            <Button type="button" variant="link" disabled={isBusy} onClick={exit}>
              {SAVE_LATER_LABEL()}
            </Button>
          ) : (
            <Button type="button" variant="link" disabled={isBusy} onClick={() => goTo(SETUP_LAST_STEP)}>
              {step === 1 ? SKIP_FIRST_LABEL() : SKIP_LABEL()}
            </Button>
          )
        }
        primary={
          isReview ? (
            <LoadingButton type="button" loading={complete.isPending} disabled={isBusy} onClick={turnOn}>
              {TURN_ON_LABEL()}
            </LoadingButton>
          ) : (
            <LoadingButton
              type="button"
              loading={saveDraft.isPending}
              disabled={isBusy}
              onClick={() => goTo(returnToReview ? SETUP_LAST_STEP : step + 1)}
            >
              {CONTINUE_LABEL()}
            </LoadingButton>
          )
        }
      >
        {SETUP_STEPS[step].render((target) => {
          setReturnToReview(true);
          setStep(target);
        })}
      </SetupWizardLayout>
    </FormProvider>
  );
}
