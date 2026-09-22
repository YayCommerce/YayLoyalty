import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
  ADJUST_LIFETIME_LABEL,
  ADJUST_LIFETIME_SUBTEXT,
  CUSTOMER_NOTE_PLACEHOLDER,
  CUSTOMER_NOTE_PREVIEW,
  NOTIFY_BY_EMAIL_LABEL,
  SHOW_CUSTOMER_NOTE_LABEL,
} from '../customers-adjust-points.copy';
import type { AdjustFormState } from '../customers-adjust-points.form-state';

/**
 * Lifetime toggle · customer-facing description toggle · notify-by-email
 * toggle. The lifetime toggle is wired into the submit payload; the other
 * two stay UI-only this ticket — nothing they hold is sent to the server
 * yet. Reused by both the single (count === 1) and bulk (count > 1) layouts.
 */
export function AdjustPointsOptions({
  state,
  onChange,
  notifyCountLabel,
}: {
  state: Pick<AdjustFormState, 'adjustLifetime' | 'showCustomerNote' | 'customerNote' | 'notifyByEmail'>;
  onChange: (patch: Partial<AdjustFormState>) => void;
  /** Bulk only — "340 emails" beside the notify checkbox. */
  notifyCountLabel?: string;
}) {
  return (
    <div className="flex flex-col gap-3">
      <label className="flex items-start gap-2.5">
        <Checkbox
          checked={state.adjustLifetime}
          onCheckedChange={(checked) => onChange({ adjustLifetime: checked === true })}
        />
        <span className="flex flex-col gap-0.5">
          <span className="text-sm font-medium">{ADJUST_LIFETIME_LABEL()}</span>
          <span className="text-muted-foreground text-xs">{ADJUST_LIFETIME_SUBTEXT()}</span>
        </span>
      </label>

      {/* UI-only this ticket — see the file docblock. */}
      <div className="flex flex-col gap-2">
        <label className="flex items-center justify-between gap-2.5">
          <span className="text-sm font-medium">{SHOW_CUSTOMER_NOTE_LABEL()}</span>
          <Switch
            checked={state.showCustomerNote}
            onCheckedChange={(checked) => onChange({ showCustomerNote: checked })}
          />
        </label>
        {state.showCustomerNote ? (
          <Input
            value={state.customerNote}
            onChange={(event) => onChange({ customerNote: event.target.value })}
            placeholder={CUSTOMER_NOTE_PLACEHOLDER()}
          />
        ) : (
          <p className="text-muted-foreground text-xs">{CUSTOMER_NOTE_PREVIEW()}</p>
        )}
      </div>

      {/* UI-only this ticket — see the file docblock. */}
      <label className="flex items-center justify-between gap-2.5">
        <span className="flex items-center gap-2.5">
          <Checkbox
            checked={state.notifyByEmail}
            onCheckedChange={(checked) => onChange({ notifyByEmail: checked === true })}
          />
          <span className="text-sm font-medium">{NOTIFY_BY_EMAIL_LABEL()}</span>
        </span>
        {notifyCountLabel && <span className="text-muted-foreground text-xs">{notifyCountLabel}</span>}
      </label>
    </div>
  );
}
