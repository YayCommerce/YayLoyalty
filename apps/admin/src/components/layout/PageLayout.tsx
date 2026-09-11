import { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * Shared page shell: header row (title + description + optional actions) above
 * the page's own content. `title`/`description`/`actions` are fully
 * caller-supplied — no copy lives here.
 */
export default function PageLayout({
  title,
  description,
  actions,
  children,
  className,
}: {
  title: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('mx-auto flex max-w-5xl flex-col gap-4 p-6', className)}>
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
          {description ? <p className="text-muted-foreground text-sm">{description}</p> : null}
        </div>
        {actions ? <div className="flex items-center gap-2">{actions}</div> : null}
      </div>
      {children}
    </div>
  );
}
