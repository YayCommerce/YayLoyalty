import { Controller, useFormContext } from 'react-hook-form';

import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { PROGRAM_STATUS_DESCRIPTION, PROGRAM_STATUS_TITLE } from './general-settings.copy';
import type { GeneralSettingsFormValues } from './general-settings.form';

/** The whole program's on/off switch: PHP's ProgramGate::program_enabled() reads it. */
export function ProgramStatusCard() {
  const { control } = useFormContext<GeneralSettingsFormValues>();

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div className="flex flex-col gap-1.5">
          <CardTitle>
            <label htmlFor="yayloyalty-program-enabled">{PROGRAM_STATUS_TITLE()}</label>
          </CardTitle>
          <CardDescription>{PROGRAM_STATUS_DESCRIPTION()}</CardDescription>
        </div>
        <Controller
          control={control}
          name="enabled"
          render={({ field }) => (
            <Switch
              id="yayloyalty-program-enabled"
              className="w-11!"
              checked={field.value}
              onCheckedChange={field.onChange}
            />
          )}
        />
      </CardHeader>
    </Card>
  );
}
