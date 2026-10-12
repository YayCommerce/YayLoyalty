import path from 'path';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

/**
 * Builds the storefront blocks' editor script (src/blocks/index.tsx) to assets/dist/blocks/editor.js.
 * React and the block APIs are WordPress's own globals, never bundled.
 * Same output for Lite and Pro, so BUILD_TYPE is not read here.
 */
const GLOBALS: Record<string, string> = {
  react: 'React',
  '@wordpress/blocks': 'wp.blocks',
  '@wordpress/block-editor': 'wp.blockEditor',
  '@wordpress/server-side-render': 'wp.serverSideRender',
};

export default defineConfig({
  plugins: [react({ jsxRuntime: 'classic' })],
  build: {
    minify: true,
    emptyOutDir: true,
    outDir: path.resolve(__dirname, '../../assets/dist/blocks'),
    lib: {
      entry: path.resolve(__dirname, 'src/blocks/index.tsx'),
      formats: ['iife'],
      name: 'yayloyaltyBlocks',
      fileName: () => 'editor.js',
    },
    rollupOptions: {
      external: Object.keys(GLOBALS),
      output: { globals: GLOBALS },
    },
  },
});
