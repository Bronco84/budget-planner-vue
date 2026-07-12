import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import prettier from 'eslint-config-prettier';
import globals from 'globals';

export default [
  {
    ignores: [
      'public/**',
      'vendor/**',
      'node_modules/**',
      'bootstrap/ssr/**',
      'storage/**',
      'resources/js/ziggy.js',
    ],
  },
  js.configs.recommended,
  ...pluginVue.configs['flat/recommended'],
  // Turn off formatting rules that Prettier owns.
  prettier,
  {
    files: ['**/*.{js,vue}'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: {
        ...globals.browser,
        ...globals.node,
        // Ziggy route helper injected globally.
        route: 'readonly',
        Ziggy: 'readonly',
      },
    },
    rules: {
      // Page/layout components are intentionally single-word (Show, Edit, ...).
      'vue/multi-word-component-names': 'off',
      'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
      // Surfaces the stray debug logging noted in the audit.
      'no-console': 'warn',
    },
  },
];
