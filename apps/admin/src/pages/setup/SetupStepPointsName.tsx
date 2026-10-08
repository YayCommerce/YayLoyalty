import { useFormContext, useWatch } from 'react-hook-form';

import { storeCurrency, storeCurrencySymbol } from '@/lib/helpers/format.helper';
import type { SetupDraft } from '@/lib/schema/setup.schema';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
  CURRENCY_HELP,
  CURRENCY_LABEL,
  CURRENCY_READ_ONLY,
  NAME_HELP,
  NAME_PLURAL_LABEL,
  NAME_PREVIEW,
  NAME_SINGULAR_LABEL,
  NAME_TITLE,
  NOTHING_PUBLISHED,
} from './setup-wizard.copy';
import { SetupStepHeading } from './SetupFields';

/** Step 1: both forms of the points name, previewed in a sentence a customer reads, and the store currency. */
export function SetupStepPointsName() {
  const {
    register,
    control,
    formState: { errors },
  } = useFormContext<SetupDraft>();
  const plural = useWatch({ control, name: 'points_label_plural' });

  return (
    <>
      <SetupStepHeading title={NAME_TITLE()} description={NOTHING_PUBLISHED()} />

      <Card>
        <CardContent className="flex flex-col gap-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field>
              <FieldLabel htmlFor="setup-label-singular">{NAME_SINGULAR_LABEL()}</FieldLabel>
              <Input id="setup-label-singular" maxLength={30} {...register('points_label_singular')} />
              <FieldError errors={[errors.points_label_singular]} />
            </Field>
            <Field>
              <FieldLabel htmlFor="setup-label-plural">{NAME_PLURAL_LABEL()}</FieldLabel>
              <Input id="setup-label-plural" maxLength={30} {...register('points_label_plural')} />
              <FieldError errors={[errors.points_label_plural]} />
            </Field>
          </div>
          <FieldDescription>{NAME_HELP()}</FieldDescription>
          <div className="bg-muted rounded-md p-3 text-sm font-medium">{NAME_PREVIEW(plural?.trim() ?? '')}</div>
        </CardContent>
      </Card>

      <Card>
        <CardContent>
          <Field>
            <FieldLabel>{CURRENCY_LABEL()}</FieldLabel>
            <FieldContent className="flex flex-row items-center gap-2">
              <span className="font-medium">{`${storeCurrency()} (${storeCurrencySymbol()})`}</span>
              <Badge variant="secondary">{CURRENCY_READ_ONLY()}</Badge>
            </FieldContent>
            <FieldDescription>{CURRENCY_HELP()}</FieldDescription>
          </Field>
        </CardContent>
      </Card>
    </>
  );
}
