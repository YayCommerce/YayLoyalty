/// <reference types="vite/client" />

/** VITE_IS_PRO is the string 'true' or 'false', set from BUILD_TYPE in vite.config.ts. */
interface ImportMetaEnv {
  readonly VITE_IS_PRO: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
