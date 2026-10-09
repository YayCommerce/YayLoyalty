import { useState } from 'react';
import { useFormContext, useWatch } from 'react-hook-form';
import { useDebouncedValue } from 'rooks';

import type { DesignPreviewViewer } from '@/lib/api/design.api';
import { useSetupPreviewQuery } from '@/lib/queries/setup.queries';
import type { SetupDraft } from '@/lib/schema/setup.schema';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import {
  PREVIEW_ERROR,
  PREVIEW_TITLE,
  PREVIEW_VIEWER_GUEST,
  PREVIEW_VIEWER_MEMBER,
} from '../settings/design-settings.copy';
import { FRAME_HEIGHT_PX, REDRAW_DELAY_MS, STAGES } from '../settings/DesignLauncherPreview';
import { LauncherPreviewFrame } from '../settings/LauncherPreviewFrame';

/**
 * Step 4's launcher as the storefront will draw it once live: the draft's look, labels and earn rates,
 * redrawn by the server. Desktop only; the Design tab has the device switch.
 */
export function SetupLookPreview() {
  const { control } = useFormContext<SetupDraft>();
  const draft = useWatch({ control }) as SetupDraft;
  const [debouncedDraft] = useDebouncedValue(draft, REDRAW_DELAY_MS);
  const [viewer, setViewer] = useState<DesignPreviewViewer>('member');
  const preview = useSetupPreviewQuery(debouncedDraft ?? draft, viewer);

  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-center gap-2">
        <CardTitle className="mr-auto">{PREVIEW_TITLE()}</CardTitle>
        <Segmented size="sm" value={viewer} onValueChange={(value) => value && setViewer(value as DesignPreviewViewer)}>
          <SegmentedItem value="member">{PREVIEW_VIEWER_MEMBER()}</SegmentedItem>
          <SegmentedItem value="guest">{PREVIEW_VIEWER_GUEST()}</SegmentedItem>
        </Segmented>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {preview.isError && !preview.data && <p className="text-destructive text-xs">{PREVIEW_ERROR()}</p>}

        {STAGES.map(({ key, label, skeletonClassName }) => (
          <div key={key} className="flex flex-col gap-1.5">
            <div className="text-muted-foreground text-[11px] tracking-[0.06em] uppercase">{label()}</div>
            {preview.data ? (
              <LauncherPreviewFrame
                html={preview.data[key]}
                title={label()}
                device="desktop"
                viewportHeightPx={FRAME_HEIGHT_PX.desktop[key]}
                highlightTextKey={null}
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
