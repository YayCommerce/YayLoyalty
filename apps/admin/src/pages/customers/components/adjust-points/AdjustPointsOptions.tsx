import type { ReactNode } from 'react';

import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
  ADJUST_LIFETIME_LABEL,
  ADJUST_LIFETIME_SUBTEXT,
  ADJUST_TIER_LABEL,
  ADJUST_TIER_SUBTEXT,
  CUSTOMER_NOTE_PLACEHOLDER,
  FOOTER_NOTICE,
  NOTIFY_BY_EMAIL_LABEL,
  SHOW_CUSTOMER_NOTE_HELPER,
  SHOW_CUSTOMER_NOTE_LABEL,
} from '../customers-adjust-points.copy';
import { withAdjustLifetime, type AdjustFormState } from '../customers-adjust-points.form-state';

/** The adjustment's options, for both layouts. */
export function AdjustPointsOptions({
  state,
  onChange,
  notifyCountLabel,
  showTier = false,
}: {
  state: Pick<AdjustFormState, 'adjustLifetime' | 'adjustTier' | 'showCustomerNote' | 'customerNote' | 'notifyByEmail'>;
  onChange: (patch: Partial<AdjustFormState>) => void;
  /** Pro with tiers on: the "Count toward tier points" option. */
  showTier?: boolean;
  /** Bulk only: "340 emails" beside the notify checkbox. */
  notifyCountLabel?: string;
}) {
  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col divide-y border-t">
        <CheckOption
          checked={state.adjustLifetime}
          onChange={(checked) => onChange(withAdjustLifetime(checked))}
          label={ADJUST_LIFETIME_LABEL()}
          subtext={ADJUST_LIFETIME_SUBTEXT()}
        />
        {showTier && (
          <CheckOption
            checked={state.adjustTier}
            onChange={(checked) => onChange({ adjustTier: checked })}
            label={ADJUST_TIER_LABEL()}
            subtext={ADJUST_TIER_SUBTEXT()}
          />
        )}
        <CheckOption
          checked={state.notifyByEmail}
          onChange={(checked) => onChange({ notifyByEmail: checked })}
          label={
            <>
              {NOTIFY_BY_EMAIL_LABEL()}
              {notifyCountLabel && (
                <span className="bg-foreground text-background rounded-full px-2 py-0.5 text-xs font-medium">
                  {notifyCountLabel}
                </span>
              )}
            </>
          }
          subtext={FOOTER_NOTICE()}
        />
      </div>

      <div className="flex items-start gap-3 rounded-lg border p-4">
        <Switch
          id="adjust-points-customer-note"
          checked={state.showCustomerNote}
          onCheckedChange={(checked) => onChange({ showCustomerNote: checked })}
        />
        <div className="flex min-w-0 flex-1 flex-col gap-2">
          <label htmlFor="adjust-points-customer-note" className="flex flex-col gap-0.5">
            <span className="text-sm font-medium">{SHOW_CUSTOMER_NOTE_LABEL()}</span>
            <span className="text-muted-foreground text-xs">{SHOW_CUSTOMER_NOTE_HELPER()}</span>
          </label>
          <Input
            value={state.customerNote}
            onChange={(event) => onChange({ customerNote: event.target.value })}
            placeholder={CUSTOMER_NOTE_PLACEHOLDER()}
            maxLength={120}
            disabled={!state.showCustomerNote}
          />
        </div>
      </div>
    </div>
  );
}

function CheckOption({
  checked,
  onChange,
  label,
  subtext,
}: {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label: ReactNode;
  subtext: string;
}) {
  return (
    <label className="flex items-start gap-2.5 py-3">
      <Checkbox checked={checked} onCheckedChange={(value) => onChange(value === true)} />
      <span className="flex flex-col gap-0.5">
        <span className="flex items-center gap-2 text-sm font-medium">{label}</span>
        <span className="text-muted-foreground text-xs">{subtext}</span>
      </span>
    </label>
  );
}
