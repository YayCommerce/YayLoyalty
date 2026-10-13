import { Controller, useFormContext, useWatch } from 'react-hook-form';

import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import type { ExpirySettingsFormValues } from '@/pages/settings/expiry-settings.form';
import {
  BATCH_MONTHS_HINT,
  BATCH_MONTHS_LABEL,
  BATCH_MONTHS_UNIT,
  MODEL_BATCHES_BODY,
  MODEL_BATCHES_TITLE,
  MODEL_EXPLAINER,
  MODEL_INACTIVITY_BODY,
  MODEL_INACTIVITY_TITLE,
  MODEL_ONE_WAY,
  MODEL_TITLE,
} from './expiry-model.copy';
import { ExpiryBatchExample } from './ExpiryBatchExample';

type Model = ExpirySettingsFormValues['model'];

const MONTHS_ID = 'yayloyalty-expiry-batch-months';

/** Mirrors SettingsHelper::RANGES['expiry_months'], the source of truth. */
const MONTHS_RANGE = { min: 1, max: 120 };

function ModelCard({
  title,
  body,
  selected,
  disabled,
  onSelect,
}: {
  title: string;
  body: string;
  selected: boolean;
  disabled: boolean;
  onSelect: () => void;
}) {
  return (
    <button
      type="button"
      role="radio"
      aria-checked={selected}
      disabled={disabled}
      onClick={onSelect}
      className={cn(
        'flex flex-1 flex-col gap-1 rounded-md border p-3 text-left text-sm transition-colors',
        selected ? 'border-primary bg-primary/5' : 'hover:bg-muted/50',
        disabled && 'cursor-not-allowed opacity-50',
      )}
    >
      <span className="font-medium">{title}</span>
      <span className="text-muted-foreground text-xs">{body}</span>
    </button>
  );
}

/**
 * Settings → Expiry, Pro: the expiry model and, under batches, how long each batch lives. Once batches is
 * saved the inactivity card is disabled: the server refuses the switch back (yayloyalty_expiry_model_one_way).
 * ExpirySettingsPage owns Save, and confirms the switch to batches before sending it.
 */
export function ExpiryModelSection({ savedModel }: { savedModel: Model }) {
  const { control } = useFormContext<ExpirySettingsFormValues>();
  const [enabled, model, months] = useWatch({ control, name: ['enabled', 'model', 'batch_months'] });

  if (!enabled) {
    return null;
  }

  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          {MODEL_TITLE()}
          <Badge variant="outline">{MODEL_ONE_WAY()}</Badge>
        </CardTitle>
        <CardDescription>{MODEL_EXPLAINER()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <Controller
          control={control}
          name="model"
          render={({ field }) => (
            <div role="radiogroup" aria-label={MODEL_TITLE()} className="flex flex-col gap-3 sm:flex-row">
              <ModelCard
                title={MODEL_INACTIVITY_TITLE()}
                body={MODEL_INACTIVITY_BODY()}
                selected={field.value === 'inactivity'}
                disabled={savedModel === 'batches'}
                onSelect={() => field.onChange('inactivity')}
              />
              <ModelCard
                title={MODEL_BATCHES_TITLE()}
                body={MODEL_BATCHES_BODY()}
                selected={field.value === 'batches'}
                disabled={false}
                onSelect={() => field.onChange('batches')}
              />
            </div>
          )}
        />

        {model === 'batches' && (
          <>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor={MONTHS_ID}>{BATCH_MONTHS_LABEL()}</Label>
              <div className="flex flex-wrap items-center gap-3">
                <Controller
                  control={control}
                  name="batch_months"
                  render={({ field }) => (
                    <NumberInputRoot
                      size="small"
                      className="w-32"
                      min={MONTHS_RANGE.min}
                      max={MONTHS_RANGE.max}
                      value={field.value}
                      onValueChange={(value) => field.onChange(value ?? MONTHS_RANGE.min)}
                    >
                      <NumberInputInput id={MONTHS_ID} />
                      <NumberInputUnit unit={BATCH_MONTHS_UNIT()} />
                    </NumberInputRoot>
                  )}
                />
                <span className="text-muted-foreground text-sm">{BATCH_MONTHS_HINT()}</span>
              </div>
            </div>
            <ExpiryBatchExample months={months} />
          </>
        )}
      </CardContent>
    </Card>
  );
}
