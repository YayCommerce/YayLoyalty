/**
 * Whether this is the Pro bundle. Fixed at build time, never a runtime flag or licence state.
 * It is a `VITE_` env var set in vite.config.ts, not a `define`, which the dev server leaves unsubstituted.
 */
export const IS_PRO_BUILD: boolean = import.meta.env.VITE_IS_PRO === 'true';
