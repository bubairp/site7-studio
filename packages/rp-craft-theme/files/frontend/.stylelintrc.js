module.exports = {
  extends: ['stylelint-config-standard-scss'],
  rules: {
    'at-rule-no-unknown': null,
    'scss/at-rule-no-unknown': true,
    'color-function-notation': 'legacy',
    'alpha-value-notation': 'number',
    'selector-class-pattern': null,
    'no-descending-specificity': null,
    'scss/dollar-variable-pattern': null,
  },
  ignoreFiles: [
    '../../web/themes/front/**/*',
    'node_modules/**/*',
  ],
};