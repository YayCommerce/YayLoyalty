import { Controller, useFormContext, type FieldPath } from 'react-hook-form';

import { storeCurrency } from '@/lib/helpers/format.helper';
import type { SetupDraft } from '@/lib/schema/setup.schema';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';

/** Shared pieces of the wizard's steps: the step heading and the two kinds of number field. */

export function SetupStepHeading({ title, description }: { title: string; description?: string }) {
  return (
    <div className="flex flex-col gap-1">
      <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
      {description ? <p className="text-muted-foreground text-sm">{description}</p> : null}
    </div>
  );
}

type WholeField =
  'earn.points' | 'signup.points' | 'review.points' | 'reward.points' | 'reward.min_points' | 'reward.max_percent';

/** A whole number of points or percent, with its unit and its error under it. */
export function SetupWholeInput({
  name,
  id,
  min,
  max,
  unit,
  disabled,
}: {
  name: WholeField;
  id: string;
  min: number;
  max?: number;
  unit?: string;
  disabled?: boolean;
}) {
  const { control } = useFormContext<SetupDraft>();

  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState }) => (
        <div className="flex flex-col gap-1">
          <NumberInputRoot
            size="small"
            className="w-36"
            min={min}
            max={max}
            disabled={disabled}
            value={field.value}
            onValueChange={field.onChange}
          >
            <NumberInputInput id={id} />
            {unit ? <NumberInputUnit unit={unit} /> : null}
          </NumberInputRoot>
          <FieldError errors={[fieldState.error]} />
        </div>
      )}
    />
  );
}

/** A money amount, typed and kept as a decimal string ("1", "2.50"), in the store currency. */
export function SetupAmountInput({
  name,
  id,
}: {
  name: Extract<FieldPath<SetupDraft>, `${string}.amount`>;
  id: string;
}) {
  const { register, getFieldState, formState } = useFormContext<SetupDraft>();
  const { error } = getFieldState(name, formState);

  return (
    <div className="flex flex-col gap-1">
      <div className="flex items-center gap-2">
        <Input id={id} inputMode="decimal" className="w-28" {...register(name)} />
        <span className="text-muted-foreground text-sm">{storeCurrency()}</span>
      </div>
      <FieldError errors={[error]} />
    </div>
  );
}
