import { ReactNode } from 'react';
import { Controller, FieldPath, FieldValues, useFormContext } from 'react-hook-form';

import { Label } from '@/components/ui/label';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';

/**
 * A labelled, bounded whole number in the surrounding react-hook-form form, with a unit and an optional
 * hint beside it. Clearing it falls back to `min`: the field is never empty.
 */
export function FormNumberField<T extends FieldValues>({
  name,
  label,
  unit,
  hint,
  min,
  max,
}: {
  name: FieldPath<T>;
  label: string;
  unit: string;
  hint?: ReactNode;
  min: number;
  max: number;
}) {
  const { control } = useFormContext<T>();
  const id = `yayloyalty-${name.replaceAll('_', '-').replaceAll('.', '-')}`;

  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      <div className="flex flex-wrap items-center gap-3">
        <Controller
          control={control}
          name={name}
          render={({ field }) => (
            <NumberInputRoot
              size="small"
              className="w-32"
              min={min}
              max={max}
              value={field.value}
              onValueChange={(value) => field.onChange(value ?? min)}
            >
              <NumberInputInput id={id} />
              <NumberInputUnit unit={unit} />
            </NumberInputRoot>
          )}
        />
        {hint && <span className="text-muted-foreground text-sm">{hint}</span>}
      </div>
    </div>
  );
}
