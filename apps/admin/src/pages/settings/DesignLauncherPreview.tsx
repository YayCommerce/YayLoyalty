import { useMemo } from 'react';
import { useFormContext, useWatch } from 'react-hook-form';

import { DESIGN_PREVIEW_FIELDS, type DesignPreviewInput } from '@/lib/api/design.api';
import type { Design } from '@/lib/schema/design.schema';
import { PREVIEW_TITLE } from './design-settings.copy';
import { LauncherPreviewCard } from './LauncherPreviewCard';

/** The launcher preview drawn from the Design form's unsaved values. */
export function DesignLauncherPreview() {
  const { control } = useFormContext<Design>();
  // Only what the launcher draws: editing the pages it shows on (or fields it does not render) needs no redraw.
  const [launcher, isHiddenOnMobile, isRewardCatalogShown] = useWatch({
    control,
    name: DESIGN_PREVIEW_FIELDS,
  });
  const input = useMemo<DesignPreviewInput>(
    () => ({
      launcher,
      visibility: { hide_on_mobile: isHiddenOnMobile },
      panel: { show_reward_catalog: isRewardCatalogShown },
    }),
    [launcher, isHiddenOnMobile, isRewardCatalogShown],
  );

  return <LauncherPreviewCard input={input} title={PREVIEW_TITLE()} />;
}
