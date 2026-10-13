import { useState } from 'react';
import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { sprintf } from '@wordpress/i18n';

import { PAGE_PICKER_LIMIT, type WpPageOption } from '@/lib/api/design.api';
import { roleOptions } from '@/lib/helpers/meta-options.helper';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { usePublishedPagesByIdsQuery, usePublishedPagesSearchQuery } from '@/lib/queries/design.queries';
import { designVisibilityScopeSchema, type Design } from '@/lib/schema/design.schema';
import { useDebouncedSearchCommit } from '@/hooks/useDebouncedSearchCommit';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { MultiSelectCombobox } from '@/components/ui/custom/multi-select-combobox';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { ProGate } from '@/components/pro/ProGate';
import {
  VISIBILITY_HIDE_FOR_ROLES_DESCRIPTION,
  VISIBILITY_HIDE_FOR_ROLES_LABEL,
  VISIBILITY_HIDE_FOR_ROLES_PLACEHOLDER,
  VISIBILITY_HIDE_ON_MOBILE_LABEL,
  VISIBILITY_INCLUDE_PAGES_EMPTY,
  VISIBILITY_INCLUDE_PAGES_LABEL,
  VISIBILITY_INCLUDE_PAGES_LIMIT_NOTE,
  VISIBILITY_INCLUDE_PAGES_PLACEHOLDER,
  VISIBILITY_INCLUDE_PAGES_SEARCH_PLACEHOLDER,
  VISIBILITY_PRODUCT_MESSAGE_LABEL,
  VISIBILITY_SCOPE_LABEL,
  VISIBILITY_SECTION_DESCRIPTION,
  VISIBILITY_SECTION_TITLE,
  VISIBILITY_THANKYOU_MESSAGE_LABEL,
} from './design-settings.copy';
import { visibilityScopeLabel } from './design-settings.option-labels';

const pageOption = (page: WpPageOption) => ({
  value: String(page.id),
  label: new DOMParser().parseFromString(page.title.rendered, 'text/html').body.textContent || `#${page.id}`,
});

type Visibility = Design['visibility'];
type VisibilityToggle = { [K in keyof Visibility]: Visibility[K] extends boolean ? K : never }[keyof Visibility];

/** One on/off visibility setting with its label. */
function VisibilitySwitchField({ name, label }: { name: VisibilityToggle; label: string }) {
  const { control } = useFormContext<Design>();
  const id = `design-visibility-${name.replaceAll('_', '-')}`;

  return (
    <Field>
      <FieldLabel htmlFor={id}>{label}</FieldLabel>
      <Controller
        control={control}
        name={`visibility.${name}`}
        render={({ field }) => (
          <Switch id={id} className="w-11!" checked={field.value} onCheckedChange={field.onChange} />
        )}
      />
    </Field>
  );
}

/** Scope, the chosen pages, hide on mobile, hide for roles, the product-page line, the thank-you points — the custom_pages scope and its page picker are Pro. */
export function DesignVisibilitySection() {
  const { control } = useFormContext<Design>();
  const scope = useWatch({ control, name: 'visibility.scope' });
  const showPagePicker = IS_PRO_BUILD && scope === 'custom_pages';
  const includePageIds = useWatch({ control, name: 'visibility.include_page_ids' });
  const [pageSearch, setPageSearch] = useState('');
  const { commit: commitPageSearch } = useDebouncedSearchCommit(setPageSearch);
  const searchQuery = usePublishedPagesSearchQuery(pageSearch, showPagePicker);
  // Chosen pages by id: a search can leave them out of the results, but their badges still need names.
  const chosenQuery = usePublishedPagesByIdsQuery(includePageIds, showPagePicker);
  const pageOptions = (searchQuery.data ?? []).map(pageOption);
  const chosenPageLabels = Object.fromEntries((chosenQuery.data ?? []).map(pageOption).map((o) => [o.value, o.label]));
  const atLimit = (searchQuery.data?.length ?? 0) >= PAGE_PICKER_LIMIT;

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

        {/* Lite can land here only through a stored Pro scope; the select above blocks picking it. */}
        {scope === 'custom_pages' && (
          <Field>
            <FieldLabel>
              {VISIBILITY_INCLUDE_PAGES_LABEL()}
              {!IS_PRO_BUILD && <ProGate inline />}
            </FieldLabel>
            <FieldContent>
              {showPagePicker && (
                <Controller
                  control={control}
                  name="visibility.include_page_ids"
                  render={({ field }) => (
                    <MultiSelectCombobox
                      value={field.value.join(',')}
                      onChange={(value) => field.onChange(value ? value.split(',').map(Number) : [])}
                      options={pageOptions}
                      placeholder={VISIBILITY_INCLUDE_PAGES_PLACEHOLDER()}
                      search={{
                        onSearchChange: commitPageSearch,
                        placeholder: VISIBILITY_INCLUDE_PAGES_SEARCH_PLACEHOLDER(),
                        emptyText: VISIBILITY_INCLUDE_PAGES_EMPTY(),
                        selectedLabels: chosenPageLabels,
                      }}
                      footer={atLimit && sprintf(VISIBILITY_INCLUDE_PAGES_LIMIT_NOTE(), PAGE_PICKER_LIMIT)}
                    />
                  )}
                />
              )}
            </FieldContent>
          </Field>
        )}

        <VisibilitySwitchField name="hide_on_mobile" label={VISIBILITY_HIDE_ON_MOBILE_LABEL()} />

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
                  options={roleOptions()}
                  placeholder={VISIBILITY_HIDE_FOR_ROLES_PLACEHOLDER()}
                />
              )}
            />
            <FieldDescription>{VISIBILITY_HIDE_FOR_ROLES_DESCRIPTION()}</FieldDescription>
          </FieldContent>
        </Field>

        <VisibilitySwitchField name="product_message" label={VISIBILITY_PRODUCT_MESSAGE_LABEL()} />

        <VisibilitySwitchField name="thankyou_message" label={VISIBILITY_THANKYOU_MESSAGE_LABEL()} />
      </CardContent>
    </Card>
  );
}
