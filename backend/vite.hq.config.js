/* *****************************************
File: vite.hq.config.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站（HQ）後台 Vue3 SPA 獨立建置設定，見 README 6.12。
***************************************** */

import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

// 主站後台獨立的 Vue3 SPA，跟 vite.vue.config.js（子站前台）完全分開的
//第三套建置——主站沒有多租戶概念，不需要 --site 這種依站點切換輸出路徑
// 的機制，固定輸出到 public/hq，固定 base path，不像子站那樣要動態決定。
export default defineConfig({
    root: resolve(__dirname, 'resources/vue_src_hq'),
    base: '/hq-assets/',
    plugins: [vue()],
    build: {
        outDir: resolve(__dirname, 'public/hq-assets'),
        emptyOutDir: true,
    },
    server: {
        port: 5175,
        // 見 vite.vue.config.js 同一段說明：dev server 跟 API 不同 origin，
        // 用內建 proxy 把 /api 轉發到真正的 Laravel 後端，不需要為此開 CORS。
        proxy: {
            '/api': {
                target: process.env.VUE_DEV_API_PROXY || 'http://127.0.0.1:8000',
                changeOrigin: true,
            },
        },
    },
});
