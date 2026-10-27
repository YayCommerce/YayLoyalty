import { useState } from 'react';
import { useDebouncedValue } from 'rooks';

import type { DesignPreviewInput, DesignPreviewViewer } from '@/lib/api/design.api';
import { useDesignPreviewQuery } from '@/lib/queries/design.queries';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import {
  PREVIEW_COLLAPSED,
  PREVIEW_DEVICE_DESKTOP,
  PREVIEW_DEVICE_MOBILE,
  PREVIEW_ERROR,
  PREVIEW_EXPANDED,
  PREVIEW_HIDDEN_ON_MOBILE,
  PREVIEW_VIEWER_GUEST,
  PREVIEW_VIEWER_MEMBER,
} from './design-settings.copy';
import { LauncherPreviewFrame, type PreviewDevice } from './LauncherPreviewFrame';

/** The two frames, top to bottom: the button alone, then the open panel. */
const STAGES = [
  { key: 'collapsed', label: PREVIEW_COLLAPSED, skeletonClassName: 'h-36' },
  { key: 'expanded', label: PREVIEW_EXPANDED, skeletonClassName: 'h-96' },
] as const;

/** Page heights inside each frame: the button needs a corner of a page, the open panel nearly a screen. */
const FRAME_HEIGHT_PX: Record<PreviewDevice, Record<(typeof STAGES)[number]['key'], number>> = {
  desktop: { collapsed: 220, expanded: 640 },
  mobile: { collapsed: 280, expanded: 760 },
};

/** Wait this long after the last change before redrawing, so typing a label sends one request, not one per letter. */
const REDRAW_DELAY_MS = 300;

type LauncherPreviewCardProps = {
  /** The unsaved launcher fields; the server fills the rest from the stored design and settings. */
  input: DesignPreviewInput;
  title: string;
};

/**
 * The launcher as the storefront draws it, redrawn by the server from unsaved design fields:
 * collapsed and expanded, on desktop or mobile, for a member or a guest.
 * Shared by Settings → Design and the setup wizard's step 4.
 */
export function LauncherPreviewCard({ input, title }: LauncherPreviewCardProps) {
  const [debouncedInput] = useDebouncedValue(input, REDRAW_DELAY_MS);
  const [device, setDevice] = useState<PreviewDevice>('desktop');
  const [viewer, setViewer] = useState<DesignPreviewViewer>('member');
  const preview = useDesignPreviewQuery(debouncedInput, viewer);

  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-center gap-2">
        <CardTitle className="mr-auto">{title}</CardTitle>
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
        {device === 'mobile' && input.visibility.hide_on_mobile && (
          <p className="text-muted-foreground text-xs">{PREVIEW_HIDDEN_ON_MOBILE()}</p>
        )}

        {preview.isError && !preview.data && <p className="text-destructive text-xs">{PREVIEW_ERROR()}</p>}

        {STAGES.map(({ key, label, skeletonClassName }) => (
          <div key={key} className="flex flex-col gap-1.5">
            <div className="text-muted-foreground text-[11px] tracking-[0.06em] uppercase">{label()}</div>
            {preview.data ? (
              <LauncherPreviewFrame
                html={preview.data[key]}
                title={label()}
                device={device}
                viewportHeightPx={FRAME_HEIGHT_PX[device][key]}
              />
            ) : (
              <Skeleton className={`${skeletonClassName} w-full`} />
            )}
          </div>
        ))}
      </CardContent>
    </Card>
  );
}
