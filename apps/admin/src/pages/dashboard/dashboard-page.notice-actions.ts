import type { Capability } from '@/lib/access';

/**
 * What a notice card can offer, described as data so the card stays free of
 * routing and mutation wiring. `cap` hides the action from users without the
 * capability (rendered through `<Can>`); `disabled` keeps a primary action
 * visible-but-inert with an explanation (the deferred reactivation).
 */
export type NoticeAction =
  | { kind: 'link'; label: string; href: string; cap?: Capability; external?: boolean }
  | { kind: 'button'; label: string; onClick: () => void; cap?: Capability }
  | { kind: 'disabled'; label: string; reason: string; cap?: Capability };

/** DOM id of the anomaly panel — `points_jump`'s primary action scrolls to it. */
export const ANOMALY_PANEL_ID = 'yayloyalty-dashboard-anomaly';

export function scrollToAnomalyPanel(): void {
  // Instant on purpose: a smooth scroll is cancelled by the layout shifts a
  // re-render can cause inside WP admin, and instant respects reduced motion.
  document.getElementById(ANOMALY_PANEL_ID)?.scrollIntoView({ block: 'start' });
}
