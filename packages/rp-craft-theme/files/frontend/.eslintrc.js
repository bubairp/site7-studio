module.exports = {
  env: {
    browser: true,
    es2021: true,
    jquery: true,
    node: true,
  },
  extends: ['eslint:recommended'],
  parserOptions: {
    ecmaVersion: 'latest',
    sourceType: 'module',
  },
  globals: {
    jQuery: 'readonly',
    $: 'readonly',
  },
  rules: {
    'no-console': 'warn',
    'no-unused-vars': 'warn',
    'no-undef': 'error',
    'prefer-const': 'error',
    'no-var': 'error',
  },
  ignorePatterns: [
    'node_modules/',
    '../../web/themes/front/',
    'wpconfig/',
  ],
};