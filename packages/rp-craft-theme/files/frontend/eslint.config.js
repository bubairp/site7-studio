const js = require("@eslint/js");

module.exports = [
  js.configs.recommended,
  {
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: "module",
      globals: {
        // Browser globals
        window: "readonly",
        document: "readonly",
        navigator: "readonly",
        console: "readonly",
        setTimeout: "readonly",
        clearTimeout: "readonly",
        setInterval: "readonly",
        clearInterval: "readonly",
        requestAnimationFrame: "readonly",
        cancelAnimationFrame: "readonly",
        fetch: "readonly",
        XMLHttpRequest: "readonly",
        localStorage: "readonly",
        sessionStorage: "readonly",
        Image: "readonly",
        FormData: "readonly",
        alert: "readonly",
        
        // Node globals
        process: "readonly",
        module: "readonly",
        require: "readonly",
        __dirname: "readonly",
        
        // Third-party libraries / App globals
        $: "readonly",
        jQuery: "readonly",
        Razorpay: "readonly",
        grecaptcha: "readonly",
        lazySizes: "readonly",
        Chartist: "readonly"
      }
    },
    rules: {
      "no-console": "warn",
      "no-unused-vars": "warn",
      "no-undef": "error",
      "prefer-const": "error",
      "no-var": "error",
      "no-redeclare": "off"
    }
  },
  {
    ignores: [
      "**/node_modules/",
      "../web/themes/front/",
      "wpconfig/",
      "dist/"
    ]
  }
];
