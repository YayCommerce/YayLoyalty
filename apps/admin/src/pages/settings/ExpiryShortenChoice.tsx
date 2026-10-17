import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import type { ExpirySettingsFormValues } from './expiry-settings.form';

type OnShorten = ExpirySettingsFormValues['expiry']['on_shorten'];

const CHOICES: { value: OnShorten; label: () => string; description: () => string }[] = [
  {
    value: 'keep',
    label: () => __('Keep current dates', 'yayloyalty'),
    description: () =>
      __(
        "Points customers already hold keep their expiry date. The new window applies from each customer's next earn or redemption.",
        'yayloyalty',
      ),
  },
  {
    value: 'apply',
    label: () => __('Apply to existing points', 'yayloyalty'),
    description: () =>
      __(
        'Expiry dates move to the new window now. Customers already past their new date lose their points within minutes.',
        'yayloyalty',
      ),
  },
];

/** The shorten confirmation's choice of what happens to stored dates (ExpiryPolicy::keeps_dates()). */
export function ExpiryShortenChoice({ value, onChange }: { value: OnShorten; onChange: (value: OnShorten) => void }) {
  return (
    <RadioGroup className="grid gap-3" value={value} onValueChange={(next) => onChange(next as OnShorten)}>
      {CHOICES.map((choice) => (
        <label
          key={choice.value}
          className={cn(
            'flex cursor-pointer items-start gap-3 rounded-md border p-3',
            value === choice.value && 'border-primary',
          )}
        >
          <RadioGroupItem value={choice.value} className="mt-0.5" />
          <span className="flex flex-col gap-1">
            <span className="text-sm font-medium">{choice.label()}</span>
            <span className="text-muted-foreground text-xs">{choice.description()}</span>
          </span>
        </label>
      ))}
    </RadioGroup>
  );
}
