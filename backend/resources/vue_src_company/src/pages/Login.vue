<template>
  <div class="login">
    <el-card class="login__card">
      <h1>公司端口登入</h1>
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
Description: 公司端口登入頁，固定登入 company 端口；登入後確認帳號有隸屬公司（沒有就無法使用，直接提示並登出）。
***************************************** */

import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { login, loadProfile, logout, switchToOtherPortal } from '../stores/auth';
import { loadCompany } from '../stores/company';

const router = useRouter();
const username = ref('');
const password = ref('');
const error = ref('');
const submitting = ref(false);

async function handleSubmit() {
  error.value = '';
  submitting.value = true;

  try {
    const loginResult = await login(username.value, password.value);
    // 帳號屬於其他端口（例如後台管理員）：已自動改登入該端口並整頁跳轉，這裡不再往下做。
    if (loginResult?.redirected) {
      ElMessage.info(`此帳號屬於${loginResult.portalLabel}，正在為您切換…`);
      return;
    }
    await loadProfile();
    // 重新讀本公司資訊（換帳號登入時不能沿用上一個帳號的公司）；沒有隸屬公司的帳號後端會拒絕。
    const result = await loadCompany(true);
    if (!result.ok) {
      // 不是公司成員（例如後台管理員）：先登出剛建立的公司端口登入，再試著改登入後台並跳轉。
      await logout();
      const switched = await switchToOtherPortal(username.value, password.value);
      if (switched?.redirected) {
        ElMessage.info(`此帳號屬於${switched.portalLabel}，正在為您切換…`);
        return;
      }
      throw new Error(result.message);
    }
    router.push({ name: 'home' });
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
