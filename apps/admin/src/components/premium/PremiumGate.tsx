import { ReactNode } from 'react';

import { UpgradeToProBadge, UpgradeToProOverlay } from '@/components/premium/upgrade-to-pro';

/**
 * Renders premium content, or the upgrade prompt when this is a Lite build.
 *
 * THIS COMPONENT SHIPS IN BOTH BUILDS. It lives in `components/premium/`, which
 * the Lite build KEEPS — as opposed to `@/premium/`, which the Lite build
 * DELETES. Lite needs this to sell the upgrade; without it there would be no
 * upsell surface at all.
 *
 * Gating is the `__IS_PREMIUM__` build constant, substituted inline by Vite, so
 * each branch collapses to a literal and the unused one is dropped from the
 * bundle. That cuts both ways: the Lite bundle loses the premium children, and
 * the Premium bundle loses the overlay.
 *
 * Two modes:
 *
 *   <PremiumGate inline />        badge, for nav items and table rows
 *   <PremiumGate>{children}</>    overlay, for whole panels
 *
 * Note it takes no `isPremium` prop and reads nothing from localized data —
 * there is deliberately no runtime premium flag. See globals.d.ts.
 */
export function PremiumGate({
  children,
  inline = false,
  className,
}: {
  children?: ReactNode;
  /** Render the compact badge rather than the full overlay. */
  inline?: boolean;
  className?: string;
}) {
  if (__IS_PREMIUM__) {
    // In a Premium build this is the only branch that survives the build.
    return <>{children ?? null}</>;
  }

  if (inline) {
    return (
      <span className={className}>
        <UpgradeToProBadge />
      </span>
    );
  }

  return <UpgradeToProOverlay />;
}

/**
 * True when this bundle was built as Premium.
 *
 * A named export purely so call sites read as intent rather than as a bare
 * global. It is the same build constant, not a runtime check — do not add one.
 */
export const isPremiumBuild = (): boolean => __IS_PREMIUM__;
