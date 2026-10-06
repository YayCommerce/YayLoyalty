import { cloneElement, isValidElement, ReactElement, ReactNode, useMemo } from 'react';
import { __ } from '@wordpress/i18n';

import { AdminData } from '@/types/localize.type';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';

/**
 * Mirrors YayLoyalty\Access\Capabilities, the source of truth.
 * Cosmetic only, since every route re-checks server-side.
 * Check IS_PRO_BUILD before can(): a Pro lock wins over a capability denial.
 */

// Partial: a broken localize degrades to "no capabilities", not a crash.
const boot: Partial<AdminData> = window.yayLoyaltyAdmin ?? {};
const CAPS = new Set<string>(boot.capabilities ?? []);

export const CAP = {
  VIEW: 'yayloyalty_view',
  MANAGE_POINTS: 'yayloyalty_manage_points',
  MANAGE_TIERS: 'yayloyalty_manage_tiers',
  MANAGE_CAMPAIGNS: 'yayloyalty_manage_campaigns',
  MANAGE_REWARDS: 'yayloyalty_manage_rewards',
  MANAGE_SETTINGS: 'yayloyalty_manage_settings',
  RUN_TOOLS: 'yayloyalty_run_tools',
} as const;

export type Capability = (typeof CAP)[keyof typeof CAP];

export const can = (cap: Capability): boolean => CAPS.has(cap);

/** Per-adjustment limit for non-administrators; 0 = unlimited. Server decides. */
export const ceiling = (): number => boot.ceiling ?? 0;

export const isAdministrator = (): boolean => !!boot.isAdministrator;

export const useCapability = (cap: Capability): boolean => useMemo(() => can(cap), [cap]);

export const PERMISSION_DENIED_MESSAGE = () => __('You do not have permission to do that.', 'yayloyalty');

/** REST error codes the backbone emits; api.ts turns each into one notice. */
export const FORBIDDEN_ERROR_CODES = ['yayloyalty_forbidden', 'yayloyalty_adjustment_ceiling'] as const;

interface CanProps {
  cap: Capability;
  /** Keep the control visible but disabled, with a tooltip; a missing button reads as broken. */
  disable?: boolean;
  /** Rendered instead of children when the cap is missing and `disable` is off. */
  fallback?: ReactNode;
  children?: ReactNode;
}

/**
 * Renders children only with `cap`; `fallback` instead, or `disable` to
 * inject `disabled`. Hide whole screens; disable controls on a visible screen.
 */
export function Can({ cap, disable = false, fallback = null, children }: CanProps) {
  if (can(cap)) {
    return <>{children}</>;
  }

  if (!disable || !isValidElement(children)) {
    return <>{fallback}</>;
  }

  // Disabled controls swallow pointer events, so the tooltip hangs off a wrapping span.
  return (
    <YayLoyaltyToolTip
      content={PERMISSION_DENIED_MESSAGE()}
      trigger={
        <span className="inline-flex" tabIndex={0}>
          {cloneElement(children as ReactElement<{ disabled?: boolean }>, { disabled: true })}
        </span>
      }
    />
  );
}
