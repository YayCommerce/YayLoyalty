import { Controller, useFormContext } from 'react-hook-form';

import { designLauncherPositionSchema } from '@/lib/schema/design.schema';
import type { SetupDraft } from '@/lib/schema/setup.schema';
import { Card, CardContent } from '@/components/ui/card';
import { ColorPicker } from '@/components/ui/color-picker';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { launcherPositionLabel } from '../settings/design-settings.option-labels';
import {
  LOOK_COLOR_HELP,
  LOOK_COLOR_LABEL,
  LOOK_LATER,
  LOOK_POSITION_LABEL,
  LOOK_TITLE,
  NOTHING_PUBLISHED,
} from './setup-wizard.copy';
import { SetupStepHeading } from './SetupFields';

/** Step 4: the two launcher choices that are enough to go live. The rest waits in Settings → Design. */
export function SetupStepLook() {
  const { control } = useFormContext<SetupDraft>();

  return (
    <>
      <SetupStepHeading title={LOOK_TITLE()} description={NOTHING_PUBLISHED()} />

      <Card>
        <CardContent className="flex flex-col gap-4">
          <Field>
            <FieldLabel>{LOOK_POSITION_LABEL()}</FieldLabel>
            <FieldContent>
              <Controller
                control={control}
                name="launcher.position"
                render={({ field }) => (
                  <Segmented value={field.value} onValueChange={(value) => value && field.onChange(value)}>
                    {designLauncherPositionSchema.options.map((position) => (
                      <SegmentedItem key={position} value={position}>
                        {launcherPositionLabel(position)}
                      </SegmentedItem>
                    ))}
                  </Segmented>
                )}
              />
            </FieldContent>
          </Field>

          <Field>
            <FieldLabel>{LOOK_COLOR_LABEL()}</FieldLabel>
            <FieldContent>
              <Controller
                control={control}
                name="launcher.brand_color"
                render={({ field, fieldState }) => (
                  <>
                    <ColorPicker value={field.value} onValueChange={field.onChange} />
                    <FieldError errors={[fieldState.error]} />
                  </>
                )}
              />
              <FieldDescription>{LOOK_COLOR_HELP()}</FieldDescription>
            </FieldContent>
          </Field>
        </CardContent>
      </Card>

      <p className="text-muted-foreground text-sm">{LOOK_LATER()}</p>
    </>
  );
}
