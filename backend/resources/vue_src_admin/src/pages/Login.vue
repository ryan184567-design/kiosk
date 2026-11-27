<template>
  <div class="login">
    <el-card class="login__card">
      <h1>管理後臺登入</h1>
      <el-form @submit.prevent="handleSubmit">
        <el-form-item label="帳號">
          <el-input v-model="username" autocomplete="username" />
        </el-form-item>
        <el-form-item label="密碼">
          <el-input v-model="password" type="password" autocomplete="current-password" show-password />
        </el-form-item>
        <el-alert v-if="error" :title="error" type="error" :closable="false" style="margin-bottom: 16px" />
        <el-button type="primary" native-type="submit" :loading="submitting" style="width: 100%">登入</el-button>
      </el-form>
    </el-card>
  </div>
</template>

<script setup>
/* *****************************************
File: Login.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 管理後台登入頁，固定登入 admin 端口，見 README 7.2。
***************************************** */

import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { login, loadProfile } from '../stores/auth';

const router = useRouter();
const username = ref('');
const password = ref('');
const error = ref('');
const submitting = ref(false);

async function handleSubmit() {
  error.value = '';
  submitting.value = true;

  try {
    const result = await login(username.value, password.value);
    // 帳號屬於其他端口（例如公司帳號）：已自動改登入該端口並整頁跳轉，這裡不再往下做。
    if (result?.redirected) {
      ElMessage.info(`此帳號屬於${result.portalLabel}，正在為您切換…`);
      return;
    }
    await loadProfile();
    router.push({ name: 'dashboard' });
  } catch (e) {
    error.value = e.message;
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.login {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f5f6f8;
}

.login__card {
  width: 360px;
}

.login__card h1 {
  font-size: 1.25rem;
  margin: 0 0 24px;
  text-align: center;
}
</style>
