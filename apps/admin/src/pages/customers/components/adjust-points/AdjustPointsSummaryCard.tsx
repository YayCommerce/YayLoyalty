import type { ReactNode } from 'react';

/** The bordered card at the top of the dialog: what this adjustment does, with an optional badge on the right. */
export function AdjustPointsSummaryCard({
  title,
  subtitle,
  badge,
  children,
}: {
  title: ReactNode;
  subtitle?: ReactNode;
  badge?: ReactNode;
  children?: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-4 rounded-xl border p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 flex-col gap-0.5">
          <span className="text-base font-semibold">{title}</span>
          {subtitle ? <span className="text-muted-foreground text-sm">{subtitle}</span> : null}
        </div>
        {badge ? (
          <span className="bg-primary/10 text-primary shrink-0 rounded-md px-2 py-1 text-sm font-medium tabular-nums">
            {badge}
          </span>
        ) : null}
      </div>
      {children}
    </div>
  );
}
