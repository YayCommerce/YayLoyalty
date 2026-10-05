import path from 'path';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig, type Plugin } from 'vite';

/**
 * Builds the storefront launcher's stylesheet (src/storefront.css) to assets/dist/storefront/launcher.css.
 * CSS only: the launcher's script is plain JS shipped as-is from assets/storefront/.
 * Same output for Lite and Pro, so BUILD_TYPE is not read here.
 */
export default defineConfig({
  root: './src',
  plugins: [tailwindcss(), scopeTokensToLauncher()],
  build: {
    minify: true,
    emptyOutDir: true,
    outDir: path.resolve(__dirname, '../../assets/dist/storefront'),
    assetsDir: '',
    rollupOptions: {
      input: { launcher: path.resolve(__dirname, 'src/storefront.css') },
      output: { assetFileNames: '[name].[ext]' },
    },
  },
});

/**
 * theme.css (shared with the admin) declares the kit's tokens on :root. On a shop page that would
 * override a theme's own --primary/--background, so this build moves them onto the launcher's root.
 * Every rem becomes px (16px base): rem follows the theme's html font-size, which shop themes change.
 * Also drops the empty JS stub Rollup emits for a CSS-only entry.
 */
function scopeTokensToLauncher(): Plugin {
  return {
    name: 'yayloyalty-scope-tokens-to-launcher',
    enforce: 'post',
    generateBundle(_options, bundle) {
      for (const [fileName, output] of Object.entries(bundle)) {
        if (output.type === 'chunk') {
          delete bundle[fileName];
        } else if (fileName.endsWith('.css') && typeof output.source === 'string') {
          output.source = output.source
            .replace(/:root\b/g, '.yayloyalty-launcher')
            .replace(/(-?\d*\.?\d+)rem\b/g, (_match, value: string) => `${+(parseFloat(value) * 16).toFixed(3)}px`);
        }
      }
    },
  };
}
