import js from '@eslint/js';
import tsPlugin from '@typescript-eslint/eslint-plugin';
import tsParser from '@typescript-eslint/parser';
import reactPlugin from 'eslint-plugin-react';
import reactHooksPlugin from 'eslint-plugin-react-hooks';
import boundaries from 'eslint-plugin-boundaries';
import testingLibrary from 'eslint-plugin-testing-library';

export default [
  js.configs.recommended,
  {
    files: ['resources/{js,admin}/**/*.{ts,tsx,js,jsx}'],
    languageOptions: {
      parser: tsParser,
      parserOptions: {
        ecmaVersion: 'latest',
        sourceType: 'module',
        ecmaFeatures: {
          jsx: true,
        },
      },
      globals: {
        // Browser globals
        window: 'readonly',
        document: 'readonly',
        navigator: 'readonly',
        localStorage: 'readonly',
        getComputedStyle: 'readonly',
        crypto: 'readonly',
        console: 'readonly',
        setTimeout: 'readonly',
        clearTimeout: 'readonly',
        setInterval: 'readonly',
        clearInterval: 'readonly',
        requestAnimationFrame: 'readonly',
        confirm: 'readonly',
        alert: 'readonly',
        prompt: 'readonly',
        // DOM Element types
        HTMLDivElement: 'readonly',
        HTMLInputElement: 'readonly',
        HTMLTextAreaElement: 'readonly',
        HTMLSelectElement: 'readonly',
        HTMLButtonElement: 'readonly',
        HTMLFormElement: 'readonly',
        HTMLElement: 'readonly',
        HTMLSpanElement: 'readonly',
        HTMLTableElement: 'readonly',
        HTMLTableSectionElement: 'readonly',
        HTMLTableRowElement: 'readonly',
        HTMLTableCellElement: 'readonly',
        HTMLTableCaptionElement: 'readonly',
        HTMLHeadingElement: 'readonly',
        HTMLParagraphElement: 'readonly',
        HTMLUListElement: 'readonly',
        HTMLLinkElement: 'readonly',
        HTMLMetaElement: 'readonly',
        Node: 'readonly',
        Element: 'readonly',
        // Event types
        KeyboardEvent: 'readonly',
        MouseEvent: 'readonly',
        Event: 'readonly',
        // Web APIs
        FormData: 'readonly',
        File: 'readonly',
        FileReader: 'readonly',
        Blob: 'readonly',
        URL: 'readonly',
        URLSearchParams: 'readonly',
        fetch: 'readonly',
        Response: 'readonly',
        Request: 'readonly',
        RequestInit: 'readonly',
        Headers: 'readonly',
        HeadersInit: 'readonly',
        AbortController: 'readonly',
        DOMException: 'readonly',
        // Built-in objects
        Promise: 'readonly',
        Error: 'readonly',
        JSON: 'readonly',
        Date: 'readonly',
        Math: 'readonly',
        Array: 'readonly',
        Object: 'readonly',
        String: 'readonly',
        Number: 'readonly',
        Boolean: 'readonly',
        Map: 'readonly',
        Set: 'readonly',
        WeakMap: 'readonly',
        WeakSet: 'readonly',
        Symbol: 'readonly',
        Proxy: 'readonly',
        Reflect: 'readonly',
        Intl: 'readonly',
      },
    },
    plugins: {
      '@typescript-eslint': tsPlugin,
      'react': reactPlugin,
      'react-hooks': reactHooksPlugin,
    },
    settings: {
      react: {
        version: 'detect',
      },
    },
    rules: {
      // ======================================
      // قواعد حرجة لمنع React Error #130
      // ======================================

      // منع استخدام متغيرات غير معرفة
      'no-undef': 'error',

      // التأكد من أن JSX يستخدم متغيرات معرفة
      'react/jsx-no-undef': ['error', { allowGlobals: true }],

      // منع JSX بدون React في scope (غير مطلوب في React 17+)
      'react/react-in-jsx-scope': 'off',

      // ======================================
      // قواعد React Hooks
      // ======================================

      // التأكد من صحة استخدام Hooks
      'react-hooks/rules-of-hooks': 'error',

      // التأكد من صحة dependencies في useEffect/useCallback/useMemo
      'react-hooks/exhaustive-deps': 'warn',

      // ======================================
      // قواعد TypeScript
      // ======================================

      // منع الاستخدام الغير ضروري للشروط (مثل if(undefined))
      '@typescript-eslint/no-unnecessary-condition': 'off', // يتطلب type-checking

      // منع any بشكل صريح
      '@typescript-eslint/no-explicit-any': 'warn',

      // التأكد من أن المتغيرات مستخدمة
      '@typescript-eslint/no-unused-vars': ['warn', {
        argsIgnorePattern: '^_',
        varsIgnorePattern: '^_',
      }],

      // ======================================
      // قواعد React عامة
      // ======================================

      // التأكد من وجود key في القوائم
      'react/jsx-key': ['error', { checkFragmentShorthand: true }],

      // منع render لأنواع غير صالحة
      'react/no-render-return-value': 'error',

      // منع استخدام children كـ prop مع dangerouslySetInnerHTML
      'react/no-danger-with-children': 'error',

      // التأكد من صحة أنواع children
      'react/no-children-prop': 'warn',

      // ======================================
      // قواعد عامة
      // ======================================

      // منع console.log في الإنتاج (تحذير فقط)
      'no-console': ['warn', { allow: ['warn', 'error', 'group', 'groupEnd'] }],

      // منع debugger
      'no-debugger': 'error',

      // تعطيل قاعدة no-unused-vars الأساسية لصالح TypeScript
      'no-unused-vars': 'off',
    },
  },
  {
    files: ['resources/admin/**/*.{ts,tsx,js,jsx}'],
    rules: {
      'no-restricted-imports': ['error', {
        patterns: [
          {
            group: ['@app', '@app/*', '@pages', '@pages/*', '@widgets', '@widgets/*', '@features', '@features/*'],
            message: 'The independent admin application cannot import operational application layers.',
          },
        ],
      }],
    },
  },
  {
    // Test files — إضافة globals خاصة بالاختبار
    files: ['resources/js/__tests__/**/*.{ts,tsx}'],
    languageOptions: {
      globals: {
        sessionStorage: 'readonly',
      },
    },
    rules: {
      'no-redeclare': 'off',
    },
  },
  {
    // ======================================
    // FSD layer boundaries (Phase 0 scaffolding)
    // ======================================
    // Enforces one-directional imports:
    //   app → pages → widgets → features → entities → shared
    // 'legacy' = code not yet migrated into a layer (anything else under resources/js).
    // It may import any layer so the existing app keeps building; as a file migrates into
    // a layer it leaves 'legacy' and the one-directional rule applies to it.
    // Rules are 'error' so they are not swallowed by --max-warnings.
    // The typescript import resolver lets boundaries resolve both '.ts(x)' extensions and
    // every '@/*' / '@<layer>/*' alias from tsconfig, so cross-layer imports are enforced.
    files: ['resources/js/**/*.{ts,tsx}'],
    plugins: { boundaries },
    settings: {
      'import/resolver': {
        typescript: { project: './tsconfig.json' },
      },
      'boundaries/elements': [
        { type: 'app', pattern: 'resources/js/app/**', mode: 'full' },
        { type: 'app', pattern: 'resources/js/app.tsx', mode: 'full' },
        { type: 'pages', pattern: 'resources/js/pages/**', mode: 'full' },
        { type: 'widgets', pattern: 'resources/js/widgets/**', mode: 'full' },
        { type: 'features', pattern: 'resources/js/features/**', mode: 'full' },
        { type: 'entities', pattern: 'resources/js/entities/**', mode: 'full' },
        { type: 'shared', pattern: 'resources/js/shared/**', mode: 'full' },
      ],
      'boundaries/ignore': [
        'resources/js/__tests__/**',
      ],
    },
    rules: {
      'boundaries/element-types': ['error', {
        default: 'disallow',
        rules: [
          { from: ['app'], allow: ['app', 'pages', 'widgets', 'features', 'entities', 'shared'] },
          { from: ['pages'], allow: ['pages', 'widgets', 'features', 'entities', 'shared'] },
          { from: ['widgets'], allow: ['widgets', 'features', 'entities', 'shared'] },
          { from: ['features'], allow: ['features', 'entities', 'shared'] },
          { from: ['entities'], allow: ['entities', 'shared'] },
          { from: ['shared'], allow: ['shared'] },
        ],
      }],
    },
  },
  {
    // تجاهل ملفات معينة
    ignores: [
      'node_modules/**',
      'vendor/**',
      'public/**',
      'storage/**',
      'bootstrap/**',
      '*.config.js',
      '*.config.ts',
      'vite.config.*',
    ],
  },
  {
    // Test files — testing-library prevention rules.
    // Severity is 'warn' now; Tasks 3/4/5 retire existing violations, then
    // Task 5 step 5.8 tightens the relevant rules to 'error'.
    //
    // NOTE: the brief lists 'no-container-queries' verbatim, but the installed
    // eslint-plugin-testing-library v7.16.2 removed that name in favor of
    // 'no-container' (same upstream rule). The literal name would crash lint
    // with "Could not find 'no-container-queries' in plugin 'testing-library'",
    // so we use the live equivalent. Documented in task-1-report.md.
    files: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
    plugins: { 'testing-library': testingLibrary },
    rules: {
      'testing-library/no-unnecessary-act': 'warn',
      'testing-library/no-await-sync-queries': 'warn',
      'testing-library/prefer-find-by': 'warn',
      'testing-library/prefer-screen-queries': 'warn',
      'testing-library/no-container': 'warn',
    },
  },
];
