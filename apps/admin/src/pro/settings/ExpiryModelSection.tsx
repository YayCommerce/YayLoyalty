import { useState } from 'react';
import { useController, useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { FormNumberField } from '@/components/ui/custom/form-number-field';
import { expiryFieldRange, type ExpirySettingsFormValues } from '@/pages/settings/expiry-settings.form';
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
  SWITCH_CONFIRM_ACTION,
  SWITCH_CONFIRM_BODY,
  SWITCH_CONFIRM_TITLE,
} from './expiry-model.copy';
import { ExpiryBatchExample } from './ExpiryBatchExample';

type Model = ExpirySettingsFormValues['model'];

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
 * Settings → Expiry, Pro: the expiry model and, under batches, how long each batch lives. Picking batches
 * confirms first, since the server refuses the switch back (yayloyalty_expiry_model_one_way); once batches
 * is saved the inactivity card is disabled. ExpirySettingsPage owns Save.
 */
export function ExpiryModelSection({ savedModel }: { savedModel: Model }) {
  const { control } = useFormContext<ExpirySettingsFormValues>();
  const [enabled, months] = useWatch({ control, name: ['enabled', 'batch_months'] });
  const { field: model } = useController({ control, name: 'model' });
  const [isConfirmOpen, setConfirmOpen] = useState(false);

  if (!enabled) {
    return null;
  }

  const pickBatches = () => {
    if (savedModel === 'batches') {
      model.onChange('batches');
    } else {
      setConfirmOpen(true);
    }
  };

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
        <div role="radiogroup" aria-label={MODEL_TITLE()} className="flex flex-col gap-3 sm:flex-row">
          <ModelCard
            title={MODEL_INACTIVITY_TITLE()}
            body={MODEL_INACTIVITY_BODY()}
            selected={model.value === 'inactivity'}
            disabled={savedModel === 'batches'}
            onSelect={() => model.onChange('inactivity')}
          />
          <ModelCard
            title={MODEL_BATCHES_TITLE()}
            body={MODEL_BATCHES_BODY()}
            selected={model.value === 'batches'}
            disabled={false}
            onSelect={pickBatches}
          />
        </div>

        {model.value === 'batches' && (
          <>
            <FormNumberField<ExpirySettingsFormValues>
              name="batch_months"
              label={BATCH_MONTHS_LABEL()}
              unit={BATCH_MONTHS_UNIT()}
              hint={BATCH_MONTHS_HINT()}
              {...expiryFieldRange('batch_months')}
            />
            <ExpiryBatchExample months={months} />
          </>
        )}
      </CardContent>

      <ConfirmDialog
        open={isConfirmOpen}
        onOpenChange={setConfirmOpen}
        title={SWITCH_CONFIRM_TITLE()}
        description={SWITCH_CONFIRM_BODY()}
        confirmLabel={SWITCH_CONFIRM_ACTION()}
        cancelLabel={__('Cancel', 'yayloyalty')}
        onConfirm={() => {
          model.onChange('batches');
          setConfirmOpen(false);
        }}
      />
    </Card>
  );
}
