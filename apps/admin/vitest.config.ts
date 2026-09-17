import path from 'node:path';
import { defineConfig } from 'vitest/config';

// Separate from vite.config.ts on purpose: the app config wires
// vite-plugin-external and Tailwind, neither of which a node unit test needs.
// Tests live under tests/ (outside tsconfig's `include`) so `tsc --noEmit`
// keeps its existing baseline; Vitest transpiles them with esbuild.
export default defineConfig({
  resolve: {
    alias: { '@': path.resolve(__dirname, 'src') },
  },
  test: {
    include: ['tests/**/*.test.ts'],
    environment: 'node',
  },
});
