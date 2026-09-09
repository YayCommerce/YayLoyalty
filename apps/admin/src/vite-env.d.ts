/// <reference types="vite/client" />

/*
 * The build-plan gate lives in a Vite `define`, not in import.meta.env.
 *
 * `VITE_PLAN` was removed from here, from both env files, and from the dead
 * `isPro` / `isLite` consts in lib/utils.ts. Nothing consumed them, and an
 * exported const must be constant-folded across a module boundary to be
 * tree-shaken, whereas a define is substituted inline at every usage site.
 *
 * __IS_PREMIUM__ is declared in globals.d.ts when the gate lands (Phase 3).
 *
 * No custom ImportMetaEnv is declared: vite/client already provides it, and no
 * app code reads import.meta.env. vite.config.ts reads VITE_SERVER_ORIGIN on
 * the Node side via loadEnv, which does not need a browser-side type.
 */
