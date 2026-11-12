<template>
  <div class="config-list">
    <el-tabs v-model="activeModule" tab-position="top">
      <el-tab-pane v-for="tab in moduleTabs" :key="tab.code" :label="tab.label" :name="tab.code">
        <SystemConfigEditor v-if="activeModule === tab.code" :module="tab.code" />
      </el-tab-pane>
    </el-tabs>
  </div>
</template>

<script setup>
/* *****************************************
File: SystemConfigList.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 系統環境配置管理頁面，見 README 17.4。改成橫向分頁（一個
cfg_module 一個分頁），取代舊版的垂直手風琴——這裡只負責「有哪些分頁」，
每個分頁實際的表格／存檔邏輯都交給可複用的 SystemConfigEditor.vue
（見該檔說明，插件設定畫面也是掛同一個元件）。SSO 相關設定已移出，改到
插件管理（見 README 6.10、resources/vue_src_admin/src/pages/plugin）
底下的 LDAP／SSO 插件各自的設定畫面管理，不再跟站台核心設定混在一起。

分頁清單刻意寫死（不是查 system_configs 有哪些 distinct cfg_module 就
全部列出來）：這張表跟插件設定共用，若動態列出所有出現過的模組，插件
一旦被啟用過（哪怕後來又停用），它的 cfg_module（plugin:xxx:yyy）就會
一起被列成這裡的分頁，兩種完全不同性質的設定混在同一份清單裡（已實測
踩過這個坑）。核心系統設定「有哪些分頁」跟「有哪些模組」一樣，都是
開發階段決定的結構，不是後台資料自己長出來的，所以固定在這裡維護。
***************************************** */

import { ref } from 'vue';
import SystemConfigEditor from '../../components/SystemConfigEditor.vue';

// 目前核心系統設定只剩「頁面設置」（原 site_basic，見 17.4）；日後若新增
// 其他核心模組（非插件），在這裡補一筆即可。
const moduleTabs = [{ code: 'site_basic', label: '頁面設置' }];

const activeModule = ref(moduleTabs[0]?.code || '');
</script>
