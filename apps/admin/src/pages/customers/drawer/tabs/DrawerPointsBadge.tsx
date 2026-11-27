import { Badge } from '@/components/ui/badge';
import { ledgerPointsBadge } from '../customer-drawer.copy';

/** "+150 points" in green or "-50 points" in red, the same on every drawer tab. */
export function DrawerPointsBadge({ points }: { points: number }) {
  return (
    <Badge variant={points < 0 ? 'destructive-soft' : 'success-soft'} className="border-current/20 tabular-nums">
      {ledgerPointsBadge(points)}
    </Badge>
  );
}
