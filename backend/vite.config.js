/* *****************************************
File: vite.config.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: Blade 資產建置設定（@vite 頁面用），見 README 3.2、4.2.1 第 2 點。
***************************************** */

import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // 固定用 IPv4 loopback，不要讓 Node 把預設的 'localhost' 解析成
        // IPv6 的 ::1——CSP 的 host-source 語法不支援 IPv6 字面值（含中
        // 括號），瀏覽器會直接判定該來源整條無效並忽略，導致 SecurityHeaders
        // 放行的 dev server 來源形同虛設。只固定 host，不固定 port，
        // port 衝突時 Vite 仍會照樣自動換一個，見 SecurityHeaders 的讀取邏輯。
        host: '127.0.0.1',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
