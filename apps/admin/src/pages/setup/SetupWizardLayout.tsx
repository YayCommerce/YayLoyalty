import type { ReactNode } from 'react';

import { SETUP_LAST_STEP } from '@/lib/schema/setup.schema';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import { BACK_LABEL, EXIT_LABEL, STEP_OF, WIZARD_TITLE } from './setup-wizard.copy';

/**
 * The full-screen frame around every step: title, step count and Exit on top; Back, the skip link
 * and the primary action below. Outside AppLayout, so it brings its own Toaster.
 */
export function SetupWizardLayout({
  step,
  isBusy,
  onExit,
  onBack,
  skip,
  primary,
  children,
}: {
  step: number;
  isBusy: boolean;
  onExit: () => void;
  onBack: (() => void) | null;
  /** The secondary action in the footer: a skip link, or step 5's "Save and finish later". */
  skip: ReactNode;
  /** The primary action: Continue, or step 5's "Turn on my program". */
  primary: ReactNode;
  children: ReactNode;
}) {
  return (
    <div className="bg-background flex min-h-[calc(100vh-32px)] flex-col">
      <Toaster />
      <header className="flex items-center gap-4 border-b px-6 py-3">
        <span className="font-semibold">{WIZARD_TITLE()}</span>
        <span className="text-muted-foreground text-sm">{STEP_OF(step, SETUP_LAST_STEP)}</span>
        <Button type="button" variant="ghost" className="ml-auto" disabled={isBusy} onClick={onExit}>
          {EXIT_LABEL()}
        </Button>
      </header>

      <main className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 px-6 py-8">{children}</main>

      <footer className="flex items-center gap-4 border-t px-6 py-3">
        {onBack ? (
          <Button type="button" variant="ghost" disabled={isBusy} onClick={onBack}>
            {BACK_LABEL()}
          </Button>
        ) : null}
        <div className="ml-auto flex items-center gap-4">
          {skip}
          {primary}
        </div>
      </footer>
    </div>
  );
}
