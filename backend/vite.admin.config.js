/* *****************************************
File: vite.admin.config.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站管理後台 Vue3 SPA 獨立建置設定，見 README 7.2、9 節。
***************************************** */

import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

// 子站管理後台（對應 admin 這個 portal，見 README 7.2）第四套獨立建置：
// 跟 vite.config.js（Blade 資產）、vite.vue.config.js（子站前台，走 8 節
// 的三層客製化）、vite.hq.config.js（主站）都是分開的建置。管理後台的
// 業務邏輯（Role/User/Menu/SystemConfig 管理）不因子站而異，不需要像
// 前台那樣依站點切換輸出路徑，固定輸出到 public/admin-assets 即可。
export default defineConfig({
    root: resolve(__dirname, 'resources/vue_src_admin'),
    base: '/admin-assets/',
    plugins: [vue()],
    build: {
        outDir: resolve(__dirname, 'public/admin-assets'),
        emptyOutDir: true,
    },
    server: {
        port: 5176,
        // 見 vite.vue.config.js／vite.hq.config.js 同一段說明：dev server
        // 跟 API 不同 origin，用內建 proxy 轉發 /api，不需要為此開 CORS。
        proxy: {
            '/api': {
                target: process.env.VUE_DEV_API_PROXY || 'http://127.0.0.1:8000',
                changeOrigin: true,
            },
        },
    },
});
