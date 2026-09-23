import { Button } from '@/components/ui/button';
import {
  ACTION_LEAVE_AS_IS,
  ACTION_WRITE_OFF_NEGATIVE_BALANCE,
  negativeWriteOffConsequence,
} from './customer-drawer.panels.copy';

/**
 * 5a's two actions — two options, no default, per the artboard's own
 * framing (writing off costs something, leaving it costs nothing but takes
 * longer to resolve itself).
 *
 * NEITHER IS WIRED. "Write off" could be implemented today without a new
 * endpoint — it is `POST /customers/{id}/adjust` with `points: +deficit`
 * and a fixed note, the same endpoint `CustomerDrawerActions.tsx` already
 * calls. Deliberately left disabled until the note string and whether a
 * write-off can be undone are decided.
 */
export function CustomerDrawerNegativeActions({ customerFirstName }: { customerFirstName: string }) {
  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
        <Button variant="outline" size="sm" disabled>
          {ACTION_WRITE_OFF_NEGATIVE_BALANCE()}
        </Button>
        <Button variant="outline" size="sm" disabled>
          {ACTION_LEAVE_AS_IS()}
        </Button>
      </div>
      <p className="text-muted-foreground text-xs">{negativeWriteOffConsequence(customerFirstName)}</p>
    </div>
  );
}
