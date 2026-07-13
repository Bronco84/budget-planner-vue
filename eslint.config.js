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
      // Inertia (<Head>, <Link>) and Headless UI (<Dialog>) legitimately use
      // names that collide with HTML elements.
      'vue/no-reserved-component-names': 'off',
      // Deliberate control-character stripping in filename sanitization.
      'no-control-regex': 'off',
      'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
      // Surfaces the stray debug logging noted in the audit.
      'no-console': 'warn',
      // Known pre-existing issues (mostly in Budgets/Show.vue). Kept visible as
      // warnings rather than blocking CI; each needs a proper fix verified with
      // the app running, not a blind rewrite.
      'vue/no-side-effects-in-computed-properties': 'warn',
      'vue/no-use-v-if-with-v-for': 'warn',
      'vue/no-v-text-v-html-on-component': 'warn',
    },
  },
];
