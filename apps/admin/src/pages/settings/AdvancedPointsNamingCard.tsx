import { useFormContext, useWatch } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { parseWPCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldContent, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
  POINTS_NAMING_ERROR,
  POINTS_NAMING_HELP,
  POINTS_NAMING_PLURAL,
  POINTS_NAMING_PREVIEW_TITLE,
  POINTS_NAMING_SINGULAR,
  POINTS_NAMING_TITLE,
} from './advanced-settings.copy';
import type { AdvancedSettingsFormValues } from './advanced-settings.form';

type LabelField = 'points_label_singular' | 'points_label_plural';

function PointsLabelField({ name, label }: { name: LabelField; label: string }) {
  const { register, formState } = useFormContext<AdvancedSettingsFormValues>();
  const id = `yayloyalty-${name.replaceAll('_', '-')}`;
  const invalid = !!formState.errors.advanced?.[name];

  return (
    <Field data-invalid={invalid}>
      <FieldLabel htmlFor={id}>{label}</FieldLabel>
      <FieldContent>
        <Input id={id} aria-invalid={invalid} {...register(`advanced.${name}`)} />
        {invalid && <FieldError>{POINTS_NAMING_ERROR()}</FieldError>}
      </FieldContent>
    </Field>
  );
}

/**
 * The merchant's name for points, shown as the three sentences a customer reads rather than two bare words.
 * PHP's SettingsHelper::points_label() picks singular or plural on the storefront and in the emails.
 */
export function AdvancedPointsNamingCard() {
  const { control } = useFormContext<AdvancedSettingsFormValues>();
  const [singular, plural] = useWatch({
    control,
    name: ['advanced.points_label_singular', 'advanced.points_label_plural'],
  });

  const sentences = [
    /* translators: 1: number of points, 2: the store's plural points label. */
    sprintf(__('You have %1$s %2$s', 'yayloyalty'), formatPoints(9850), plural),
    /* translators: 1: the store's singular points label, 2: one unit of the store currency. */
    sprintf(__('Earn 1 %1$s per %2$s you spend', 'yayloyalty'), singular, parseWPCurrency(1)),
    /* translators: 1: number of points, 2: the store's plural points label, 3: discount amount. */
    sprintf(__('Redeem %1$s %2$s for %3$s off', 'yayloyalty'), formatPoints(500), plural, parseWPCurrency(5)),
  ];

  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle>{POINTS_NAMING_TITLE()}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <div className="grid gap-3 sm:grid-cols-2">
          <PointsLabelField name="points_label_singular" label={POINTS_NAMING_SINGULAR()} />
          <PointsLabelField name="points_label_plural" label={POINTS_NAMING_PLURAL()} />
        </div>
        <div className="bg-muted rounded-md border p-3">
          <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
            {POINTS_NAMING_PREVIEW_TITLE()}
          </p>
          <p className="text-sm">{sentences.map((sentence) => `“${sentence}”`).join(' · ')}</p>
        </div>
        <p className="text-muted-foreground text-xs">{POINTS_NAMING_HELP()}</p>
      </CardContent>
    </Card>
  );
}
