import { ReactNode } from 'react';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { UpgradeToProBadge, UpgradeToProOverlay } from '@/components/pro/UpgradeToPro';

/**
 * Renders pro content, or the upgrade prompt in a Lite build. Ships in
 * both builds (unlike @/pro/), gated by the build constant only.
 * `inline` renders the badge for nav items and rows; otherwise the overlay.
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

/** The build constant, wrapped so call sites read as intent. Not a runtime check; do not add one. */
export const isProBuild = (): boolean => IS_PRO_BUILD;
