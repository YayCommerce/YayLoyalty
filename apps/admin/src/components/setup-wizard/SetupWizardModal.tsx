import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { useCompleteSetupMutation, useDismissSetupMutation } from '@/lib/queries/setup.queries';
import { cn } from '@/lib/utils';
import { Button, LoadingButton } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/custom/dialog';
import { toast } from '@/components/ui/toast';
import { EarnPointsStep } from './EarnPointsStep';
import { LauncherLookStep } from './LauncherLookStep';
import { NamePointsStep } from './NamePointsStep';
import { ReviewProgramStep } from './ReviewProgramStep';
import { setupDefaults, toSetupPayload, WIZARD_STEP_COUNT, type SetupForm, type WizardStep } from './setup-wizard.form';
import { SpendPointsStep } from './SpendPointsStep';

/**
 * The five-step setup wizard. It starts from setupDefaults(), and nothing is saved until
 * "Turn on my program" on step 5, which sends every answer in one POST /setup.
 */
export function SetupWizardModal({ onClose }: { onClose: () => void }) {
  const form = useForm<SetupForm>({ defaultValues: setupDefaults() });
  const [step, setStep] = useState<WizardStep>(1);
  // Set by an Edit link on step 5: Continue then returns to the review instead of walking on.
  const [returnToReview, setReturnToReview] = useState(false);
  const complete = useCompleteSetupMutation();
  const dismiss = useDismissSetupMutation();

  const goToStep = (next: WizardStep) => {
    setReturnToReview(true);
    setStep(next);
  };

  const onContinue = () => {
    if (returnToReview) {
      setReturnToReview(false);
      setStep(5);
      return;
    }
    setStep((current) => Math.min(current + 1, WIZARD_STEP_COUNT) as WizardStep);
  };

  const finish = () => {
    complete.mutate(toSetupPayload(form.getValues()), {
      onSuccess: () => {
        toast.success(__('Your loyalty program is on.', 'yayloyalty'));
        onClose();
      },
      onError: (error) => toastApiError(error, __('Your setup could not be saved. Try again.', 'yayloyalty')),
    });
  };

  // Exit discards every answer and closes at once; the request that stops the wizard reopening runs on its own.
  const exit = () => {
    dismiss.mutate();
    onClose();
  };

  return (
    <Dialog open>
      <DialogContent
        showCloseButton={false}
        onInteractOutside={(event) => event.preventDefault()}
        onEscapeKeyDown={(event) => event.preventDefault()}
        className="max-h-[calc(100vh-4rem)] p-0 sm:max-w-225"
      >
        <div className="bg-muted/30 flex flex-col">
          <div className="bg-background flex items-center gap-4 border-b px-8 py-4">
            <DialogTitle className="text-base">{__('YayLoyalty setup', 'yayloyalty')}</DialogTitle>
            <DialogDescription className="sr-only">
              {__('Set up your loyalty program in five steps.', 'yayloyalty')}
            </DialogDescription>
            <span className="ml-auto text-sm font-semibold">
              {/* translators: 1: the current step number; 2: the number of steps. */}
              {sprintf(__('Step %1$d of %2$d', 'yayloyalty'), step, WIZARD_STEP_COUNT)}
            </span>
            <button
              type="button"
              className="text-primary cursor-pointer text-xs hover:underline disabled:opacity-50"
              disabled={complete.isPending}
              onClick={exit}
            >
              {__('Exit setup', 'yayloyalty')}
            </button>
          </div>

          <div className="bg-background flex gap-1.5 border-b px-8 py-3.5">
            {Array.from({ length: WIZARD_STEP_COUNT }, (_, index) => (
              <div key={index} className="bg-border h-1 flex-1 overflow-hidden">
                <div
                  className={cn(
                    'bg-primary h-full origin-left transition-transform duration-300 ease-out motion-reduce:transition-none',
                    index < step ? 'scale-x-100' : 'scale-x-0',
                  )}
                />
              </div>
            ))}
          </div>

          <div className="flex flex-col gap-5 px-8 pt-8 pb-10">
            {step === 1 && <NamePointsStep form={form} />}
            {step === 2 && <EarnPointsStep form={form} />}
            {step === 3 && <SpendPointsStep form={form} />}
            {step === 4 && <LauncherLookStep form={form} />}
            {step === 5 && <ReviewProgramStep form={form} onEdit={goToStep} />}

            <div className="flex items-center gap-3.5 border-t pt-4">
              <Button
                variant="link"
                className="px-0"
                disabled={step === 1}
                onClick={() => setStep((current) => (current - 1) as WizardStep)}
              >
                {__('‹ Back', 'yayloyalty')}
              </Button>
              <div className="ml-auto flex items-center gap-4">
                {step === 5 ? (
                  <LoadingButton loading={complete.isPending} disabled={complete.isPending} onClick={finish}>
                    {__('Turn on my program', 'yayloyalty')}
                  </LoadingButton>
                ) : (
                  <Button onClick={onContinue}>{__('Continue', 'yayloyalty')}</Button>
                )}
              </div>
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
