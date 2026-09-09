import path from 'path';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig, loadEnv } from 'vite';
import pluginExternal from 'vite-plugin-external';

// Dev server port — must match RegisterDev.php (includes/Engine/Register/RegisterDev.php)
const DEV_PORT = 3030;

// https://vitejs.dev/config/
export default defineConfig(({ mode }) => {
  const envDir = path.resolve(__dirname, 'env');
  const env = loadEnv(mode, envDir);

  // The single build-plan gate. BUILD_TYPE=lite produces the Lite bundle;
  // anything else (including unset, so a plain `pnpm build` stays Premium)
  // produces Premium.
  //
  // A `define` rather than import.meta.env on purpose: it is substituted
  // inline at every usage site, so `if (__IS_PREMIUM__)` collapses to a
  // literal and the dead branch is eliminated. An exported const derived from
  // import.meta.env has to be constant-folded across a module boundary, which
  // is less reliable — see the sentinel check in scripts/validate-build.php.
  const isPremiumBuild = process.env.BUILD_TYPE !== 'lite';

  return {
    root: './src',
    envDir,

    define: {
      __IS_PREMIUM__: JSON.stringify(isPremiumBuild),
    },

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
      origin: env.VITE_SERVER_ORIGIN,
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
