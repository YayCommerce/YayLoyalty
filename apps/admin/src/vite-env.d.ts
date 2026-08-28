/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_PLAN: 'lite' | 'pro';
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
