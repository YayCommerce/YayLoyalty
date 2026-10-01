import { useState } from 'react';
import { useResizeObserverRef } from 'rooks';

import { cn } from '@/lib/utils';

/** A phone and a small desktop window; the desktop width must clear the mobile media query in styles/storefront-launcher-layout.css. */
const VIEWPORT = {
  desktop: { widthPx: 720, maxScale: 1 },
  mobile: { widthPx: 390, maxScale: 0.68 },
} as const;

export type PreviewDevice = keyof typeof VIEWPORT;

type LauncherPreviewFrameProps = {
  html: string;
  title: string;
  device: PreviewDevice;
  /** The page's height inside the frame, before scaling: the open panel needs more room than the button. */
  viewportHeightPx: number;
};

/**
 * One server-drawn launcher page, rendered at a real viewport width and scaled down to fit, so the
 * storefront's media queries (the mobile bottom sheet) apply exactly as on a phone or a desktop.
 * `sandbox=""` runs no script and gives the page no access to the admin: it is markup and CSS only.
 */
export function LauncherPreviewFrame({ html, title, device, viewportHeightPx }: LauncherPreviewFrameProps) {
  const [containerWidthPx, setContainerWidthPx] = useState(0);
  const [containerRef] = useResizeObserverRef(([entry]) => setContainerWidthPx(entry.contentRect.width));
  const { widthPx, maxScale } = VIEWPORT[device];
  const scale = containerWidthPx > 0 ? Math.min(maxScale, containerWidthPx / widthPx) : maxScale;

  return (
    <div ref={containerRef} className="w-full">
      <div
        className={cn('border-border overflow-hidden rounded-md border', device === 'mobile' && 'mx-auto')}
        style={{ width: widthPx * scale, height: viewportHeightPx * scale }}
      >
        <iframe
          title={title}
          sandbox=""
          srcDoc={html}
          tabIndex={-1}
          className="pointer-events-none block origin-top-left border-0"
          style={{ width: widthPx, height: viewportHeightPx, transform: `scale(${scale})` }}
        />
      </div>
    </div>
  );
}
