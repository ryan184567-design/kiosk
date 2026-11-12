/* *****************************************
File: vite.vue.config.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 前台 Vue3 SPA 獨立建置設定，見 README 3.2、8、9 節。
***************************************** */

import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

// 前台 Vue3 SPA 獨立建置設定，見 README 3.2、8、9。這是與 vite.config.js
// （Blade／@vite 頁面用）完全分開的另一套建置，因為輸出目的地、base path
// 都不一樣（見 8.1 三層模式）。由 php artisan vue:build 呼叫，見 18.2。
//
// VUE_BUILD_OUTDIR / VUE_BUILD_BASE 由 vue:build 指令依 --site= 是否帶入
// 動態決定：不帶 --site 時輸出到 public/vue（Tier 1/2 共用）；
// 帶 --site={code} 時輸出到 public/vue-sites/{code}（Tier 3 獨立建置，見 8.4）。
export default defineConfig({
    root: resolve(__dirname, 'resources/vue_src'),
    base: process.env.VUE_BUILD_BASE || '/vue/',
    plugins: [vue()],
    build: {
        outDir: resolve(__dirname, process.env.VUE_BUILD_OUTDIR || 'public/vue'),
        emptyOutDir: true,
    },
    server: {
        port: 5173,
        // 見 README 4.2.2：開發時 SPA 由 Vite 自己的 dev server 直接提供
        // （跟 API 不同 origin，見 4.2.1 第 2 點），fetch('/api/...') 這類
        // 相對路徑預設會打到 Vite 自己身上、根本沒有 /api 路由。用 Vite
        // 內建的 proxy 把 /api 轉發到真正的 Laravel 後端，讓瀏覽器完全
        // 感受不到跨來源這件事——不需要為此開 CORS 白名單，這是比 CORS
        // 更乾淨的解法（CORS 是給「前後端真的架在不同網域」的情境用，
        // 見 config/cors.php 開頭說明）。VUE_DEV_API_PROXY 可覆寫目標，
        // 預設對應 php artisan serve 的預設位址。
        proxy: {
            '/api': {
                target: process.env.VUE_DEV_API_PROXY || 'http://127.0.0.1:8000',
                changeOrigin: true,
            },
        },
    },
});
