import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

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
