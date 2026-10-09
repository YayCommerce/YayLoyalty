import type { ReactNode } from 'react';

/** Building blocks every wizard step shares, so the five steps read as one screen. */
export function WizardStepHeading({ title, description }: { title: string; description: string }) {
  return (
    <div className="flex flex-col gap-1.5">
      <h2 className="text-2xl font-semibold tracking-tight">{title}</h2>
      <p className="text-muted-foreground text-sm">{description}</p>
    </div>
  );
}

export function WizardSection({ children }: { children: ReactNode }) {
  return <div className="bg-background flex flex-col gap-4 rounded-md border p-5">{children}</div>;
}

/** The worked example a step's numbers produce, in the sentence a customer reads. */
export function WizardPreview({ children }: { children: ReactNode }) {
  return <div className="bg-muted rounded-md border px-4 py-3.5 text-base font-semibold">{children}</div>;
}
