import { Controller, useFormContext } from 'react-hook-form';

import type { Design } from '@/lib/schema/design.schema';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ColorPicker } from '@/components/ui/color-picker';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import {
  LAUNCHER_BRAND_COLOR_LABEL,
  LAUNCHER_CORNER_RADIUS_DESCRIPTION,
  LAUNCHER_CORNER_RADIUS_LABEL,
  LAUNCHER_SECTION_DESCRIPTION,
  LAUNCHER_SECTION_TITLE,
} from './design-settings.copy';

/** Brand colour and corner radius. */
export function DesignLauncherSection() {
  const { control } = useFormContext<Design>();

  return (
    <Card>
      <CardHeader>
        <CardTitle>{LAUNCHER_SECTION_TITLE()}</CardTitle>
        <CardDescription>{LAUNCHER_SECTION_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <Field>
          <FieldLabel>{LAUNCHER_BRAND_COLOR_LABEL()}</FieldLabel>
          <FieldContent>
            <Controller
              control={control}
              name="launcher.brand_color"
              render={({ field }) => <ColorPicker value={field.value} onValueChange={field.onChange} />}
            />
          </FieldContent>
        </Field>

        <Field>
          <FieldLabel htmlFor="design-launcher-corner-radius">{LAUNCHER_CORNER_RADIUS_LABEL()}</FieldLabel>
          <FieldContent>
            <Controller
              control={control}
              name="launcher.corner_radius"
              render={({ field }) => (
                <NumberInputRoot
                  size="small"
                  className="w-28"
                  min={0}
                  max={24}
                  value={field.value}
                  onValueChange={field.onChange}
                >
                  <NumberInputInput id="design-launcher-corner-radius" />
                  <NumberInputUnit unit="px" />
                </NumberInputRoot>
              )}
            />
            <FieldDescription>{LAUNCHER_CORNER_RADIUS_DESCRIPTION()}</FieldDescription>
          </FieldContent>
        </Field>
      </CardContent>
    </Card>
  );
}
