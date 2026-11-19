import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import Components from 'unplugin-vue-components/vite'
import { AntDesignVueResolver } from 'unplugin-vue-components/resolvers'

export default defineConfig({
  plugins: [
    vue(),
    // Ant Design Vue 元件按需自動引入（不需手動 import <a-button> 等元件）
    Components({
      resolvers: [AntDesignVueResolver({ importStyle: false })],
      dts: false,
    }),
  ],
  define: {
    // vue-i18n 編譯旗標（使用 Composition API）
    __VUE_I18N_FULL_INSTALL__: true,
    __VUE_I18N_LEGACY_API__: false,
    __INTLIFY_PROD_DEVTOOLS__: false,
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    // Vite 8 預設的 Oxc minifier 會使 vue-router 的 useRouter()/useRoute() 在正式版回傳 undefined，
    // 改用 terser 壓縮以確保正確
    minify: 'terser',
    // ant-design-vue 核心約 690 kB，無法再拆（頁面刻意不延遲載入）；本機服務載入，提高警告門檻
    chunkSizeWarningLimit: 800,
    rolldownOptions: {
      output: {
        // 第三方套件拆成獨立檔案（仍於開頁時一併載入，不使用延遲載入，原因見 src/router/index.js），
        // 程式更新時套件檔案的 hash 不變可沿用快取，也避免單一檔案超過 500 kB 的警告
        codeSplitting: {
          groups: [
            { name: 'vendor-antd-icons', test: /node_modules[\\/]@ant-design[\\/]icons/ },
            { name: 'vendor-antd', test: /node_modules[\\/](ant-design-vue|@ant-design|rc-|dayjs|lodash)/ },
            { name: 'vendor-vue', test: /node_modules[\\/](vue|@vue|vue-router|vue-i18n|@intlify)[\\/]/ },
            { name: 'vendor', test: /node_modules[\\/]/ },
          ],
        },
      },
    },
  },
  server: {
    host: '0.0.0.0', // 開發模式也允許區網訪問
    port: 5173,
    proxy: {
      // 櫃台 / 訪客螢幕同步：開發時轉送到 node 伺服器（npm run dev:server，預設 3000 埠）
      '/socket.io': {
        target: `http://localhost:${process.env.PORT || 3000}`,
        ws: true,
      },
      // 本機服務 API（例如列印訪客證）
      '/api': {
        target: `http://localhost:${process.env.PORT || 3000}`,
      },
    },
  },
  preview: {
    host: '0.0.0.0',
    port: 4173,
  },
})
