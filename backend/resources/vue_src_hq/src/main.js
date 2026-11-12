/* *****************************************
File: main.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站後台 SPA 進入點，見 README 6.12。
***************************************** */

import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';

createApp(App).use(router).mount('#app');
