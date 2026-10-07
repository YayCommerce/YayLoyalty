import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/** Numbered card shell for one section of a campaign or reward editor. */
export function EditorSection({
  step,
  title,
  contentClassName,
  children,
}: {
  step: number;
  title: string;
  contentClassName?: string;
  children?: ReactNode;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2.5 text-base">
          <span className="bg-foreground text-background flex size-5.5 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold">
            {step}
          </span>
          {title}
        </CardTitle>
      </CardHeader>
      {children && <CardContent className={cn('flex flex-col gap-4', contentClassName)}>{children}</CardContent>}
    </Card>
  );
}

/** EditorSection's loading state: the badge and title greyed out above the section's skeleton fields. */
export function EditorSectionSkeleton({
  contentClassName,
  children,
}: {
  contentClassName?: string;
  children: ReactNode;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2.5 text-base">
          <Skeleton className="size-5.5 shrink-0 rounded-full" />
          <Skeleton className="h-4 w-24" />
        </CardTitle>
      </CardHeader>
      <CardContent className={cn('flex flex-col gap-4', contentClassName)}>{children}</CardContent>
    </Card>
  );
}

/** A vertical Field's loading state: label line above its control(s). */
export function EditorFieldSkeleton({ labelClassName, children }: { labelClassName: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <Skeleton className={cn('h-3.5', labelClassName)} />
      {children}
    </div>
  );
}
