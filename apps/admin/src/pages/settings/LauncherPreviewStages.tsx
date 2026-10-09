import type { DesignPreview } from '@/lib/schema/design.schema';
import { Skeleton } from '@/components/ui/skeleton';
import { PREVIEW_COLLAPSED, PREVIEW_ERROR, PREVIEW_EXPANDED } from './design-settings.copy';
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

/** Wait this long after the last keystroke before redrawing, so typing a label sends one request, not one per letter. */
export const REDRAW_DELAY_MS = 300;

/**
 * The server-drawn launcher pages, collapsed then expanded, with a skeleton until the first ones arrive
 * and an error line when none could be drawn. Shared by the Design tab and setup step 4.
 */
export function LauncherPreviewStages({
  preview,
  device,
  highlightTextKey = null,
}: {
  preview: { data?: DesignPreview; isError: boolean };
  device: PreviewDevice;
  highlightTextKey?: string | null;
}) {
  return (
    <>
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
              highlightTextKey={highlightTextKey}
            />
          ) : (
            <Skeleton className={`${skeletonClassName} w-full`} />
          )}
        </div>
      ))}
    </>
  );
}
