import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
  ADJUST_LIFETIME_LABEL,
  ADJUST_LIFETIME_SUBTEXT,
  CUSTOMER_NOTE_PLACEHOLDER,
  NOTIFY_BY_EMAIL_LABEL,
  SHOW_CUSTOMER_NOTE_HELPER,
  SHOW_CUSTOMER_NOTE_LABEL,
} from '../customers-adjust-points.copy';
import type { AdjustFormState } from '../customers-adjust-points.form-state';

/** The adjustment's options, for both layouts. Only the lifetime toggle reaches the server. */
export function AdjustPointsOptions({
  state,
  onChange,
  notifyCountLabel,
}: {
  state: Pick<AdjustFormState, 'adjustLifetime' | 'showCustomerNote' | 'customerNote' | 'notifyByEmail'>;
  onChange: (patch: Partial<AdjustFormState>) => void;
  /** Bulk only: "340 emails" beside the notify checkbox. */
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

      {/* TODO(adjust-points): send customerNote with the adjustment and show it in the customer's points history. */}
      <div className="flex flex-col gap-2">
        <label className="flex items-start justify-between gap-2.5">
          <span className="flex flex-col gap-0.5">
            <span className="text-sm font-medium">{SHOW_CUSTOMER_NOTE_LABEL()}</span>
            <span className="text-muted-foreground text-xs">{SHOW_CUSTOMER_NOTE_HELPER()}</span>
          </span>
          <Switch
            checked={state.showCustomerNote}
            onCheckedChange={(checked) => onChange({ showCustomerNote: checked })}
          />
        </label>
        {state.showCustomerNote && (
          <Input
            value={state.customerNote}
            onChange={(event) => onChange({ customerNote: event.target.value })}
            placeholder={CUSTOMER_NOTE_PLACEHOLDER()}
          />
        )}
      </div>

      {/* TODO(adjust-points): send notifyByEmail with the adjustment and email the customers. */}
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
