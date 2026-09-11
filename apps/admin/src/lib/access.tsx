import { cloneElement, isValidElement, ReactElement, ReactNode, useMemo } from 'react';
import { __ } from '@wordpress/i18n';

import { AdminData } from '@/types/localize.type';
import { YayLoyaltyToolTip } from '@/components/ui/custom/YayLoyaltyToolTip';

/**
 * The entire client-side capability surface: what the current user may do,
 * read once from the bootstrap payload PHP localised at page render.
 *
 * THIS IS COSMETICS, NOT SECURITY. The SPA hides and disables so people are
 * not shown controls that will fail; every route re-checks on the server and
 * a 403 from there is the real gate. There is deliberately no /permissions
 * endpoint — the caps are known before the first render, and a round trip
 * would mean a flash of wrong UI.
 *
 * Cap gate vs Pro gate: they are different and must look different. A Pro
 * lock keeps the upsell badge; a cap denial greys the control with "You do
 * not have permission". PRO LOCK WINS — telling a shop manager they lack
 * permission for a feature nobody in the store has yet is a support ticket.
 * Evaluate `__IS_PRO__` / <ProGate> first, `can()` second.
 */

// Partial: a broken localize must degrade to "no capabilities", not a crash.
const boot: Partial<AdminData> = window.yayLoyaltyAdmin ?? {};
const CAPS = new Set<string>(boot.capabilities ?? []);

/** Mirrors YayLoyalty\Access\Capabilities on the PHP side. */
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
  /**
   * Keep the control visible but disabled, with a tooltip. Use for a primary
   * CTA or a row action the user can see but not use — a missing button
   * reads as a broken feature; a greyed one explains itself.
   */
  disable?: boolean;
  /** Rendered instead of children when the cap is missing and `disable` is off. */
  fallback?: ReactNode;
  children?: ReactNode;
}

/**
 * <Can cap={CAP.MANAGE_POINTS}>…</Can>                 renders children, else nothing
 * <Can cap={…} fallback={<NotFoundPage />}>…</Can>     renders fallback instead
 * <Can cap={…} disable>…</Can>                          children with `disabled` injected
 *
 * Hide for whole screens and destructive tools; disable for controls on a
 * screen the user can already see.
 */
export function Can({ cap, disable = false, fallback = null, children }: CanProps) {
  if (can(cap)) {
    return <>{children}</>;
  }

  if (!disable || !isValidElement(children)) {
    return <>{fallback}</>;
  }

  // Disabled controls swallow pointer events, so the tooltip hangs off a
  // wrapping span rather than the control itself.
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
