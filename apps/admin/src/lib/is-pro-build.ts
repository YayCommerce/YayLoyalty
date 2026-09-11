/**
 * Whether this bundle is the Pro build. No pro flag is localized from PHP:
 * one zip carries both the PHP and the JS bundle, so build type is never
 * ambiguous and there is nothing for a runtime flag to disambiguate. Licence
 * state is deliberately not an input — an expired licence stops updates, not
 * features.
 *
 * Backed by `import.meta.env.VITE_IS_PRO` (set from BUILD_TYPE in
 * vite.config.ts), not a Vite `define` — see that file's comment for why.
 *
 * NOT used by main.tsx's Pro-registration guard: that call site is what
 * `@/pro` tree-shaking out of the Lite build depends on, and reads
 * `import.meta.env.VITE_IS_PRO` directly rather than through this constant —
 * a same-file literal comparison is what Rollup/terser fold most reliably.
 * Every other Pro/Lite branch in the app should import this instead of
 * repeating that comparison.
 */
export const IS_PRO_BUILD: boolean = import.meta.env.VITE_IS_PRO === 'true';
