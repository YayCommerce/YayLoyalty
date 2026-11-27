import { ArrowRight } from 'lucide-react';

import { formatPoints } from '@/lib/helpers/points.helper';
import { AFTER_LABEL, BEFORE_LABEL } from '../customers-adjust-points.copy';

/** Two figure boxes, before → after, under a small caption. */
export function AdjustPointsBeforeAfter({
  caption,
  before,
  after,
}: {
  caption: string;
  before: number;
  after: number;
}) {
  return (
    <div className="flex flex-col gap-2">
      <span className="text-muted-foreground text-xs">{caption}</span>
      <div className="flex items-center gap-3">
        <div className="bg-muted flex flex-1 flex-col gap-0.5 rounded-md px-3 py-2.5">
          <span className="text-muted-foreground text-xs">{BEFORE_LABEL()}</span>
          <span className="text-xl font-semibold tabular-nums">{formatPoints(before)}</span>
        </div>
        <ArrowRight className="text-muted-foreground size-4 shrink-0" />
        <div className="bg-primary/10 text-primary flex flex-1 flex-col gap-0.5 rounded-md px-3 py-2.5">
          <span className="text-xs opacity-80">{AFTER_LABEL()}</span>
          <span className="text-xl font-semibold tabular-nums">{formatPoints(after)}</span>
        </div>
      </div>
    </div>
  );
}
