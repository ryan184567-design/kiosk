/* *****************************************
File: vite.company.config.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 公司端口（company）Vue3 SPA 獨立建置設定，見 README 5.4「新增端口」。
***************************************** */

import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

// 公司端口（訪客模組，每家公司的帳號登入這裡，只看得到自己公司的資料）：
// 由管理後台 vite.admin.config.js 複製，跟其他三套前端各自獨立建置，
// 固定輸出到 public/company-assets（入口頁由 CompanySpaController 回傳）。
export default defineConfig({
    root: resolve(__dirname, 'resources/vue_src_company'),
    base: '/company-assets/',
    plugins: [vue()],
    build: {
        outDir: resolve(__dirname, 'public/company-assets'),
        emptyOutDir: true,
    },
    server: {
        port: 5177,
        // 同 vite.admin.config.js：dev server 用內建 proxy 轉發 /api，不需要為此開 CORS。
        proxy: {
            '/api': {
                target: process.env.VUE_DEV_API_PROXY || 'http://127.0.0.1:8000',
                changeOrigin: true,
            },
        },
    },
});
