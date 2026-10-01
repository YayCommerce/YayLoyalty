import { useState } from 'react';
import { useFormContext, useWatch } from 'react-hook-form';
import { useDebouncedValue } from 'rooks';

import type { DesignPreviewViewer } from '@/lib/api/design.api';
import { useDesignPreviewQuery } from '@/lib/queries/design.queries';
import type { Design } from '@/lib/schema/design.schema';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import {
  PREVIEW_COLLAPSED,
  PREVIEW_DEVICE_DESKTOP,
  PREVIEW_DEVICE_MOBILE,
  PREVIEW_ERROR,
  PREVIEW_EXPANDED,
  PREVIEW_FOOTNOTE,
  PREVIEW_HIDDEN_ON_MOBILE,
  PREVIEW_TITLE,
  PREVIEW_VIEWER_GUEST,
  PREVIEW_VIEWER_MEMBER,
} from './design-settings.copy';
import { LauncherPreviewFrame, type PreviewDevice } from './LauncherPreviewFrame';

/** Page heights inside each frame: the button needs a corner of a page, the open panel nearly a screen. */
const FRAME_HEIGHT_PX: Record<PreviewDevice, { collapsed: number; expanded: number }> = {
  desktop: { collapsed: 220, expanded: 640 },
  mobile: { collapsed: 280, expanded: 760 },
};

/** Wait this long after the last keystroke before redrawing, so typing a label sends one request, not one per letter. */
const REDRAW_DELAY_MS = 300;

/**
 * The launcher as the storefront draws it (designs 1a–1c, 1e), redrawn by the server from the
 * unsaved form: collapsed and expanded, on desktop or mobile, for a member or a guest.
 */
export function DesignLauncherPreview() {
  const { control } = useFormContext<Design>();
  const design = useWatch({ control }) as Design;
  const [debouncedDesign] = useDebouncedValue(design, REDRAW_DELAY_MS);
  const [device, setDevice] = useState<PreviewDevice>('desktop');
  const [viewer, setViewer] = useState<DesignPreviewViewer>('member');
  const preview = useDesignPreviewQuery(debouncedDesign ?? design, viewer);
  const heights = FRAME_HEIGHT_PX[device];

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
        {device === 'mobile' && design.visibility?.hide_on_mobile && (
          <p className="text-muted-foreground text-xs">{PREVIEW_HIDDEN_ON_MOBILE()}</p>
        )}

        {preview.isError && !preview.data && <p className="text-destructive text-xs">{PREVIEW_ERROR()}</p>}

        <PreviewStage label={PREVIEW_COLLAPSED()}>
          {preview.data ? (
            <LauncherPreviewFrame
              html={preview.data.collapsed}
              title={PREVIEW_COLLAPSED()}
              device={device}
              viewportHeightPx={heights.collapsed}
            />
          ) : (
            <Skeleton className="h-36 w-full" />
          )}
        </PreviewStage>

        <PreviewStage label={PREVIEW_EXPANDED()}>
          {preview.data ? (
            <LauncherPreviewFrame
              html={preview.data.expanded}
              title={PREVIEW_EXPANDED()}
              device={device}
              viewportHeightPx={heights.expanded}
            />
          ) : (
            <Skeleton className="h-96 w-full" />
          )}
        </PreviewStage>

        <p className="text-muted-foreground text-xs">{PREVIEW_FOOTNOTE()}</p>
      </CardContent>
    </Card>
  );
}

function PreviewStage({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <div className="text-muted-foreground text-[11px] tracking-[0.06em] uppercase">{label}</div>
      {children}
    </div>
  );
}
