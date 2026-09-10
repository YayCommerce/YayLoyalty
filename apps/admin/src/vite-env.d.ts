/// <reference types="vite/client" />

/*
 * The build-plan gate lives in a Vite `define` (__IS_PRO__, declared in
 * globals.d.ts), not in import.meta.env. A define is substituted inline at
 * every usage site, whereas an exported const has to be constant-folded across
 * a module boundary to be tree-shaken.
 *
 * There is no env directory. Nothing in this app reads import.meta.env, and the
 * two env files that used to sit here were empty — so `loadEnv` handed
 * vite.config.ts an undefined server origin while looking as though it were
 * configured. The dev server origin is now derived from DEV_PORT in
 * vite.config.ts, where the port is already declared.
 *
 * No custom ImportMetaEnv is declared: vite/client already provides one.
 */
