/// <reference types="vite/client" />

/** VITE_IS_PRO is a string, set from BUILD_TYPE in vite.config.ts; an env var, not a `define`, which the dev server did not substitute reliably. */
interface ImportMetaEnv {
  readonly VITE_IS_PRO: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
