import type { ReactNode } from 'react';

import { SETUP_LAST_STEP } from '@/lib/schema/setup.schema';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import { BELOW_ADMIN_BAR } from '@/components/layout/Header';
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
  // Plain divs, not <header>/<main>/<footer>: styles/custom-header.css fixes every <header> and pads every
  // <main> under #wpbody-content for AppLayout. The bars stick instead, below the admin bar and at the
  // viewport's foot. The bottom margin keeps WordPress's absolutely positioned
  // #wpfooter, which this app's CSS leaves no room for, from covering the actions.
  return (
    <div className="bg-background mb-16 flex min-h-[calc(100vh-32px)] flex-col">
      <Toaster />
      <div className={cn('bg-background sticky z-20 flex items-center gap-4 border-b px-6 py-3', BELOW_ADMIN_BAR)}>
        <span className="font-semibold">{WIZARD_TITLE()}</span>
        <span className="text-muted-foreground text-sm">{STEP_OF(step, SETUP_LAST_STEP)}</span>
        <Button type="button" variant="ghost" className="ml-auto" disabled={isBusy} onClick={onExit}>
          {EXIT_LABEL()}
        </Button>
      </div>

      <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 px-6 py-8">{children}</div>

      <div className="bg-background sticky bottom-0 z-20 flex items-center gap-4 border-t px-6 py-3">
        {onBack ? (
          <Button type="button" variant="ghost" disabled={isBusy} onClick={onBack}>
            {BACK_LABEL()}
          </Button>
        ) : null}
        <div className="ml-auto flex items-center gap-4">
          {skip}
          {primary}
        </div>
      </div>
    </div>
  );
}
