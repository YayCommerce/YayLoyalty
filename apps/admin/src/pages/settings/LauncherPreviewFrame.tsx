import { useEffect, useRef, useState } from 'react';
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
  /** The storefront_text key whose text to outline, matched against the markup's data-yl-text. */
  highlightTextKey: string | null;
};

/**
 * One server-drawn launcher page, rendered at a real viewport width and scaled down to fit, so the
 * storefront's media queries (the mobile bottom sheet) apply exactly as on a phone or a desktop.
 * The sandbox runs no script: the page is markup and CSS only. `allow-same-origin` only lets the admin reach in
 * to mark the highlighted text; never add `allow-scripts` beside it, since together they let a page lift its sandbox.
 */
export function LauncherPreviewFrame({
  html,
  title,
  device,
  viewportHeightPx,
  highlightTextKey,
}: LauncherPreviewFrameProps) {
  const frameRef = useRef<HTMLIFrameElement>(null);
  const [containerWidthPx, setContainerWidthPx] = useState(0);
  const [containerRef] = useResizeObserverRef(([entry]) => setContainerWidthPx(entry.contentRect.width));
  const { widthPx, maxScale } = VIEWPORT[device];
  const scale = containerWidthPx > 0 ? Math.min(maxScale, containerWidthPx / widthPx) : maxScale;

  useEffect(() => markHighlight(frameRef.current, highlightTextKey), [highlightTextKey, html]);

  return (
    <div ref={containerRef} className="w-full">
      <div
        className={cn('border-border overflow-hidden rounded-md border', device === 'mobile' && 'mx-auto')}
        style={{ width: widthPx * scale, height: viewportHeightPx * scale }}
      >
        <iframe
          ref={frameRef}
          title={title}
          sandbox="allow-same-origin"
          srcDoc={html}
          // A redraw loads a new document after the effect ran, so the mark is re-applied here.
          onLoad={() => markHighlight(frameRef.current, highlightTextKey)}
          tabIndex={-1}
          className="pointer-events-none block origin-top-left border-0"
          style={{ width: widthPx, height: viewportHeightPx, transform: `scale(${scale})` }}
        />
      </div>
    </div>
  );
}

/** Moves the preview CSS's `[data-yl-highlighted]` outline onto every element printing `key`'s text. */
function markHighlight(frame: HTMLIFrameElement | null, key: string | null): void {
  const doc = frame?.contentDocument;

  if (!doc) {
    return;
  }

  doc.querySelectorAll('[data-yl-highlighted]').forEach((element) => element.removeAttribute('data-yl-highlighted'));

  if (key) {
    doc
      .querySelectorAll(`[data-yl-text="${key}"]`)
      .forEach((element) => element.setAttribute('data-yl-highlighted', ''));
  }
}
