import { useMemo, useState } from 'react';
import { useFormContext, useWatch } from 'react-hook-form';
import { useDebouncedValue } from 'rooks';

import { DESIGN_PREVIEW_FIELDS, type DesignPreviewInput, type DesignPreviewViewer } from '@/lib/api/design.api';
import { useDesignPreviewQuery } from '@/lib/queries/design.queries';
import type { Design } from '@/lib/schema/design.schema';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import {
  PREVIEW_DEVICE_DESKTOP,
  PREVIEW_DEVICE_MOBILE,
  PREVIEW_HIDDEN_ON_MOBILE,
  PREVIEW_TITLE,
  PREVIEW_VIEWER_GUEST,
  PREVIEW_VIEWER_MEMBER,
} from './design-settings.copy';
import { usePreviewHighlight } from './design-settings.preview-highlight';
import { type PreviewDevice } from './LauncherPreviewFrame';
import { LauncherPreviewStages, REDRAW_DELAY_MS } from './LauncherPreviewStages';

/**
 * The launcher as the storefront draws it, redrawn by the server from the
 * unsaved form: collapsed and expanded, on desktop or mobile, for a member or a guest.
 */
export function DesignLauncherPreview() {
  const { control } = useFormContext<Design>();
  // Only what the launcher draws: editing the pages it shows on (or Pro groups it does not render) needs no redraw.
  const [launcher, storefrontText, isHiddenOnMobile] = useWatch({ control, name: DESIGN_PREVIEW_FIELDS });
  const input = useMemo<DesignPreviewInput>(
    () => ({ launcher, storefront_text: storefrontText, visibility: { hide_on_mobile: isHiddenOnMobile } }),
    [launcher, storefrontText, isHiddenOnMobile],
  );
  const [debouncedInput] = useDebouncedValue(input, REDRAW_DELAY_MS);
  const [device, setDevice] = useState<PreviewDevice>('desktop');
  const [viewer, setViewer] = useState<DesignPreviewViewer>('member');
  const preview = useDesignPreviewQuery(debouncedInput, viewer);
  const { highlighted } = usePreviewHighlight();

  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-center gap-2">
        <CardTitle className="mr-auto">{PREVIEW_TITLE()}</CardTitle>
        <Segmented size="sm" value={viewer} onValueChange={(value) => value && setViewer(value as DesignPreviewViewer)}>
          <SegmentedItem value="member">{PREVIEW_VIEWER_MEMBER()}</SegmentedItem>
          <SegmentedItem value="guest">{PREVIEW_VIEWER_GUEST()}</SegmentedItem>
        </Segmented>
        <Segmented size="sm" value={device} onValueChange={(value) => value && setDevice(value as PreviewDevice)}>
          <SegmentedItem value="desktop">{PREVIEW_DEVICE_DESKTOP()}</SegmentedItem>
          <SegmentedItem value="mobile">{PREVIEW_DEVICE_MOBILE()}</SegmentedItem>
        </Segmented>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {device === 'mobile' && isHiddenOnMobile && (
          <p className="text-muted-foreground text-xs">{PREVIEW_HIDDEN_ON_MOBILE()}</p>
        )}

        <LauncherPreviewStages preview={preview} device={device} highlightTextKey={highlighted} />
      </CardContent>
    </Card>
  );
}
