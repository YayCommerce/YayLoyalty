import { useFormContext } from 'react-hook-form';

import { type Design } from '@/lib/schema/design.schema';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import {
  CUSTOM_CSS_FIELD_DESCRIPTION,
  CUSTOM_CSS_FIELD_LABEL,
  CUSTOM_CSS_SECTION_DESCRIPTION,
  CUSTOM_CSS_SECTION_TITLE,
} from '@/pages/settings/design-settings.copy';

/**
 * Pro. The merchant's custom CSS for the launcher. Stored but not yet applied to the storefront;
 * a future renderer must emit it via `wp_add_inline_style`, never interpolate it into markup, or
 * merchant CSS becomes an injection path.
 */
export function DesignCustomCssSection() {
  const { register } = useFormContext<Design>();

  return (
    <Card>
      <CardHeader>
        <CardTitle>{CUSTOM_CSS_SECTION_TITLE()}</CardTitle>
        <CardDescription>{CUSTOM_CSS_SECTION_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <Field>
          <FieldLabel htmlFor="design-custom-css">{CUSTOM_CSS_FIELD_LABEL()}</FieldLabel>
          <FieldContent>
            <Textarea id="design-custom-css" rows={6} {...register('custom_css.custom_css')} />
            <FieldDescription>{CUSTOM_CSS_FIELD_DESCRIPTION()}</FieldDescription>
          </FieldContent>
        </Field>
      </CardContent>
    </Card>
  );
}
