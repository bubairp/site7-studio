import { defineConfig } from 'vite';
import path from 'path';
import fs from 'fs';
import tailwindcss from '@tailwindcss/vite';

// Dynamically read all JS files in src/js/pages
const pagesDir = path.resolve(__dirname, 'src/js/pages');
const pageInputs = {};
if (fs.existsSync(pagesDir)) {
  fs.readdirSync(pagesDir).forEach(file => {
    if (file.endsWith('.js')) {
      const name = path.basename(file, '.js');
      pageInputs[`pages/${name}`] = path.resolve(pagesDir, file);
    }
  });
}

export default defineConfig({
  plugins: [tailwindcss()],
  // Root directory of the source files relative to this config file
  root: path.resolve(__dirname, './'),
  base: '/themes/front/',
  
  build: {
    outDir: '../web/themes/front',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        index: path.resolve(__dirname, 'src/index.js'),
        ...pageInputs
      },
      output: {
        entryFileNames: 'js/[name].[hash].js',
        chunkFileNames: 'js/[name].[hash].js',
        assetFileNames: (assetInfo) => {
          let extType = assetInfo.name.split('.').at(-1);
          if (/png|jpe?g|svg|gif|tiff|bmp|ico/i.test(extType)) {
            extType = 'img';
          } else if (/woff2?|eot|ttf|otf/i.test(extType)) {
            extType = 'fonts';
          }
          return `${extType}/[name].[hash][extname]`;
        }
      }
    }
  },

  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    }
  },

  css: {
    preprocessorOptions: {
      scss: {
        api: 'modern-compiler',
        silenceDeprecations: ['import', 'legacy-js-api', 'color-functions', 'global-builtin'],
        additionalData: `
          @use "@/css/resources/mixins.scss" as *;
        `
      }
    }
  },

  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    // Origin is used for absolute asset URLs in development
    origin: 'http://localhost:5173',
    cors: true,
    headers: {
      'Access-Control-Allow-Origin': '*',
    }
  }
});
