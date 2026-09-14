/// <reference types="vite/client" />

/**
 * The build-plan gate: `import.meta.env.VITE_IS_PRO`, set from BUILD_TYPE in
 * vite.config.ts. `VITE_IS_PRO` is a string ('true'/'false'), matching every
 * other Vite-injected env value — see src/lib/is-pro-build.ts for the parsed
 * boolean constant the rest of the app imports instead of reading this
 * directly (main.tsx's Pro-registration guard is the one exception; see its
 * comment for why).
 *
 * This used to be a Vite `define` (`__IS_PRO__`, a bare global). Moved off
 * `define` because the dev server's per-file transform did not reliably
 * substitute it — see the note in vite.config.ts.
 */
interface ImportMetaEnv {
  readonly VITE_IS_PRO: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
