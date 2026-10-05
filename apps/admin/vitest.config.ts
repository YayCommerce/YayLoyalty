import path from 'node:path';
import { defineConfig } from 'vitest/config';

// Separate from vite.config.ts: the app config wires vite-plugin-external and Tailwind,
// which a node unit test does not need. Tests go under tests/, outside tsconfig's
// `include`, so `tsc --noEmit` skips them; Vitest transpiles them with esbuild.
export default defineConfig({
  resolve: {
    alias: { '@': path.resolve(__dirname, 'src') },
  },
  test: {
    include: ['tests/**/*.test.ts'],
    environment: 'node',
  },
});
