import path from 'path';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import pluginExternal from 'vite-plugin-external';

// Dev server port — must match RegisterDev.php (includes/Engine/Register/RegisterDev.php)
const DEV_PORT = 3030;

// https://vitejs.dev/config/
export default defineConfig(() => {
  // The single build-plan gate. BUILD_TYPE=lite produces the Lite bundle;
  // anything else (including unset, so a plain `pnpm build` stays Pro)
  // produces Pro.
  //
  // Exposed via import.meta.env.VITE_IS_PRO (Vite's own env pipeline), NOT a
  // `define`. A `define` identifier (the previous `__IS_PRO__`) is only
  // guaranteed to be substituted by Vite's PRODUCTION bundler (Rollup) — the
  // DEV SERVER's per-file transform was verified to leave it as a raw,
  // unresolved identifier (reproduced directly on this project: a
  // `BUILD_TYPE=lite` dev server still served `__IS_PRO__` literally,
  // unsubstituted, in every module that referenced it — same failure
  // yay-reviews' apps/admin-settings/vite.config.js documents for the same
  // vite-plugin-external + esbuild dev-transform combination). Setting
  // process.env.VITE_IS_PRO here — before Vite's own internal env loading
  // runs — is picked up by Vite's `VITE_`-prefix convention in both dev and
  // build, reliably, with no `define` involved.
  //
  // See src/lib/is-pro-build.ts for the client-side constant this backs, and
  // main.tsx for the one call site (Pro's tree-shaking root) that reads
  // import.meta.env.VITE_IS_PRO directly rather than through that constant.
  const isProBuild = process.env.BUILD_TYPE !== 'lite';
  process.env.VITE_IS_PRO = isProBuild ? 'true' : 'false';

  return {
    root: './src',

    plugins: [
      react({ jsxRuntime: 'classic' }),
      tailwindcss(),
      pluginExternal({
        interop: 'auto',
        development: { externals: externalDev },
        production: { externals: externalProd },
      }),
    ],

    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
      },
    },
    build: {
      minify: 'terser',
      terserOptions: terserOptions,
      manifest: false,
      emptyOutDir: true,
      outDir: path.resolve('../assets', 'dist'),
      assetsDir: '',
      cssCodeSplit: false,
      rollupOptions: {
        input: {
          main: path.resolve(__dirname, 'src/main.tsx'),
        },
        output: {
          entryFileNames: 'js/[name].js',
          assetFileNames: '[name].[ext]',
        },
      },
    },
    server: {
      cors: true,
      strictPort: true,
      port: DEV_PORT,
      // WordPress serves the admin page from its own origin, so asset URLs the
      // dev server emits must be absolute or the browser resolves them against
      // the WP host and the module never loads. Derived from DEV_PORT rather
      // than read from an env file: the port is already fixed here, and the two
      // must agree.
      origin: `http://localhost:${DEV_PORT}`,
      hmr: {
        port: DEV_PORT,
        host: 'localhost',
        protocol: 'ws',
      },
    },
  };
});

const terserOptions = {
  output: {
    comments: /translators:/i,
  },
  compress: {
    passes: 2,
  },
  mangle: {
    reserved: ['__', '_n', '_nx', '_x'],
  },
};
const externalDev = {
  '@wordpress/hooks': 'wp.hooks',
  '@wordpress/i18n': 'wp.i18n',
};
const externalProd = {
  '@wordpress/element': 'wp.element',
  '@wordpress/components': 'wp.components',
  '@wordpress/hooks': 'wp.hooks',
  '@wordpress/i18n': 'wp.i18n',
  '@wordpress/date': 'wp.date',
  react: 'React',
  'react-dom': 'ReactDOM',
  'react-dom/client': 'ReactDOM',
};
