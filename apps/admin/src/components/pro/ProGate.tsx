import { ReactNode } from 'react';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { UpgradeToProBadge, UpgradeToProOverlay } from '@/components/pro/upgrade-to-pro';

/**
 * Renders pro content, or the upgrade prompt when this is a Lite build.
 *
 * THIS COMPONENT SHIPS IN BOTH BUILDS. It lives in `components/pro/`, which
 * the Lite build KEEPS — as opposed to `@/pro/`, which the Lite build
 * DELETES. Lite needs this to sell the upgrade; without it there would be no
 * upsell surface at all.
 *
 * Gating is `IS_PRO_BUILD`, the build-plan constant (see
 * `@/lib/is-pro-build`) — not a runtime check.
 *
 * Two modes:
 *
 *   <ProGate inline />        badge, for nav items and table rows
 *   <ProGate>{children}</>    overlay, for whole panels
 *
 * Note it takes no `isPro` prop and reads nothing from localized data —
 * there is deliberately no runtime pro flag.
 */
export function ProGate({
  children,
  inline = false,
  className,
}: {
  children?: ReactNode;
  /** Render the compact badge rather than the full overlay. */
  inline?: boolean;
  className?: string;
}) {
  if (IS_PRO_BUILD) {
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
 * True when this bundle was built as Pro.
 *
 * A function wrapper around `IS_PRO_BUILD` purely so call sites read as
 * intent — it is the same build constant, not a runtime check, do not add one.
 */
export const isProBuild = (): boolean => IS_PRO_BUILD;
