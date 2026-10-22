// The editor's globals (wp.blocks, wp.blockEditor, wp.serverSideRender), typed only as far as
// src/blocks/ uses them. vite.blocks.config.ts maps the imports to the globals.
declare module '@wordpress/blocks' {
  import type { ComponentType, ReactElement } from 'react';

  export function registerBlockType(
    name: string,
    settings: { icon: ReactElement; edit: ComponentType; save: () => null },
  ): unknown;
}

declare module '@wordpress/block-editor' {
  export function useBlockProps(): Record<string, unknown>;
}

declare module '@wordpress/server-side-render' {
  import type { ComponentType } from 'react';

  const ServerSideRender: ComponentType<{ block: string }>;
  export default ServerSideRender;
}
