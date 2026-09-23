import { Controller, useFormContext, useWatch } from 'react-hook-form';

import { designVisibilityScopeSchema, type Design } from '@/lib/schema/design.schema';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { MultiSelectCombobox } from '@/components/ui/custom/multi-select-combobox';
import { Field, FieldContent, FieldLabel } from '@/components/ui/field';
import { ProGate } from '@/components/pro/ProGate';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { TagsInput } from '@/components/ui/tags-input';
import {
  VISIBILITY_EXCEPT_PAGES_LABEL,
  VISIBILITY_EXCEPT_PAGES_PLACEHOLDER,
  VISIBILITY_HIDE_FOR_ROLES_LABEL,
  VISIBILITY_HIDE_FOR_ROLES_PLACEHOLDER,
  VISIBILITY_HIDE_ON_MOBILE_LABEL,
  VISIBILITY_SCOPE_LABEL,
  VISIBILITY_SECTION_DESCRIPTION,
  VISIBILITY_SECTION_TITLE,
} from './design-settings.copy';
import { visibilityScopeLabel } from './design-settings.option-labels';

// Real WP roles from the bootstrap, not free text — a merchant can't typo a role slug
// that then silently never matches anything server-side.
const ROLE_OPTIONS = Object.entries(window.yayLoyaltyMeta.wpMeta.roles).map(([value, label]) => ({
  value,
  label,
}));

/** Scope, except-pages, hide on mobile, hide for roles — custom_pages scope and its except_pages editor are Pro. */
export function DesignVisibilitySection() {
  const { control } = useFormContext<Design>();
  const scope = useWatch({ control, name: 'visibility.scope' });

  return (
    <Card>
      <CardHeader>
        <CardTitle>{VISIBILITY_SECTION_TITLE()}</CardTitle>
        <CardDescription>{VISIBILITY_SECTION_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <Field>
          <FieldLabel>{VISIBILITY_SCOPE_LABEL()}</FieldLabel>
          <FieldContent>
            <Controller
              control={control}
              name="visibility.scope"
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {designVisibilityScopeSchema.options.map((option) => {
                      // Plain option, gated in place per Key Insight 5 — custom_pages is the one Pro value.
                      const gated = option === 'custom_pages' && !IS_PRO_BUILD;

                      return (
                        <SelectItem key={option} value={option} disabled={gated}>
                          <span className="flex items-center gap-1.5">
                            {visibilityScopeLabel(option)}
                            {gated && <ProGate inline />}
                          </span>
                        </SelectItem>
                      );
                    })}
                  </SelectContent>
                </Select>
              )}
            />
          </FieldContent>
        </Field>

        {/* except_pages is gated with the custom_pages scope: it only edits when that scope is active,
            and a Lite build can only reach it via previously-saved Pro data (the select above blocks
            picking it). Plain input, gated in place per Key Insight 5. */}
        {scope === 'custom_pages' && (
          <Field>
            <FieldLabel>
              {VISIBILITY_EXCEPT_PAGES_LABEL()}
              {!IS_PRO_BUILD && <ProGate inline />}
            </FieldLabel>
            <FieldContent>
              {IS_PRO_BUILD && (
                <Controller
                  control={control}
                  name="visibility.except_pages"
                  render={({ field }) => (
                    <TagsInput
                      value={field.value}
                      onValueChange={field.onChange}
                      placeholder={VISIBILITY_EXCEPT_PAGES_PLACEHOLDER()}
                    />
                  )}
                />
              )}
            </FieldContent>
          </Field>
        )}

        <Field>
          <FieldLabel htmlFor="design-visibility-hide-on-mobile">{VISIBILITY_HIDE_ON_MOBILE_LABEL()}</FieldLabel>
          <Controller
            control={control}
            name="visibility.hide_on_mobile"
            render={({ field }) => (
              <Switch
                id="design-visibility-hide-on-mobile"
                className="w-11!"
                checked={field.value}
                onCheckedChange={field.onChange}
              />
            )}
          />
        </Field>

        <Field>
          <FieldLabel>{VISIBILITY_HIDE_FOR_ROLES_LABEL()}</FieldLabel>
          <FieldContent>
            <Controller
              control={control}
              name="visibility.hide_for_roles"
              render={({ field }) => (
                <MultiSelectCombobox
                  value={field.value.join(',')}
                  onChange={(value) => field.onChange(value ? value.split(',') : [])}
                  options={ROLE_OPTIONS}
                  placeholder={VISIBILITY_HIDE_FOR_ROLES_PLACEHOLDER()}
                />
              )}
            />
          </FieldContent>
        </Field>
      </CardContent>
    </Card>
  );
}
