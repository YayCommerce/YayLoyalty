/**
 * Whether this bundle is the Pro build. Build-time, never a runtime flag
 * or licence state. main.tsx's guard reads the env directly instead (see
 * there); every other branch imports this.
 */
export const IS_PRO_BUILD: boolean = import.meta.env.VITE_IS_PRO === 'true';
