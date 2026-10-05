import path from 'path';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import pluginExternal from 'vite-plugin-external';

// Must match DEV_SERVER in includes/Engine/Register/RegisterDev.php.
const DEV_PORT = 3030;

export default defineConfig(() => {
  // BUILD_TYPE=lite builds Lite; anything else, unset included, builds Pro.
  // Set before Vite loads its env, so the client reads it as import.meta.env.VITE_IS_PRO
  // in dev and build alike (see src/lib/is-pro-build.ts).
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
      // WordPress serves the admin page from its own origin, so the dev server's asset URLs
      // must be absolute, or the browser resolves them against the WP host.
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
