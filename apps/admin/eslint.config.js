import js from '@eslint/js';
import betterTailwindcss from 'eslint-plugin-better-tailwindcss';
import { getDefaultSelectors } from 'eslint-plugin-better-tailwindcss/defaults';
import { SelectorKind } from 'eslint-plugin-better-tailwindcss/types';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import { defineConfig, globalIgnores } from 'eslint/config';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default defineConfig([
  globalIgnores(['dist']),
  {
    files: ['**/*.{ts,tsx}'],
    extends: [
      js.configs.recommended,
      tseslint.configs.recommended,
      reactHooks.configs.flat.recommended,
      reactRefresh.configs.vite,
    ],
    plugins: {
      'better-tailwindcss': betterTailwindcss,
    },
    languageOptions: {
      ecmaVersion: 2020,
      globals: globals.browser,
    },
    settings: {
      'better-tailwindcss': {
        entryPoint: './src/main.css',
        rootFontSize: 16,
        selectors: [
          ...getDefaultSelectors(),
          {
            kind: SelectorKind.Callee,
            match: [{ type: 'strings' }],
            name: '^div|h1|h2|h3|h4|h5|h6|p|a|span|label|li$', // styled.div('...')
          },
        ],
      },
    },
    rules: {
      '@typescript-eslint/no-unused-vars': 'warn',
      'react-hooks/set-state-in-effect': 'off',
      'react-refresh/only-export-components': 'off',
      'react-hooks/incompatible-library': 'off',
      'react-hooks/exhaustive-deps': 'off',
      'better-tailwindcss/enforce-canonical-classes': 'warn',
    },
  },
]);
