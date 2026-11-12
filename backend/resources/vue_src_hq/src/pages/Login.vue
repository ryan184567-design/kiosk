<template>
  <div class="login">
    <h1>主站管理登入</h1>
    <form @submit.prevent="handleSubmit">
      <label>
        帳號
        <input v-model="username" type="text" autocomplete="username" required />
      </label>
      <label>
        密碼
        <input v-model="password" type="password" autocomplete="current-password" required />
      </label>
      <p v-if="error" class="login__error">{{ error }}</p>
      <button type="submit" :disabled="submitting">{{ submitting ? '登入中...' : '登入' }}</button>
    </form>
  </div>
</template>

<script setup>
/* *****************************************
File: Login.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站管理員登入頁，見 README 6.12。
***************************************** */

import { ref } from 'vue';
import { useRouter } from 'vue-router';
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
    await login(username.value, password.value);
    await loadProfile();
    router.push({ name: 'sites' });
  } catch (e) {
    error.value = e.message;
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.login {
  max-width: 320px;
  margin: 80px auto;
  background: #fff;
  padding: 32px;
  border-radius: 8px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
}

.login h1 {
  font-size: 1.25rem;
  margin: 0 0 24px;
}

.login form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.login label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 0.875rem;
  color: #374151;
}

.login input {
  padding: 8px 10px;
  border: 1px solid #d1d5db;
  border-radius: 4px;
  font-size: 1rem;
}

.login button {
  padding: 10px;
  border: none;
  border-radius: 4px;
  background: #1f2937;
  color: #fff;
  cursor: pointer;
  font-size: 1rem;
}

.login button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.login__error {
  color: #dc2626;
  font-size: 0.875rem;
  margin: 0;
}
</style>
