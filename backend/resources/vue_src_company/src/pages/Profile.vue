<template>
  <div class="profile">
    <el-card class="profile__card">
      <template #header>個人資料</template>
      <el-alert v-if="profileLoadError" :title="profileLoadError" type="error" :closable="false" style="margin-bottom: 16px" />
      <el-form label-width="90px" style="max-width: 420px">
        <el-form-item label="帳號">
          <el-input :model-value="username" disabled />
        </el-form-item>
        <el-form-item label="姓名">
          <el-input v-model="profileForm.name" />
        </el-form-item>
        <el-form-item label="電子郵件">
          <el-input v-model="profileForm.email" />
        </el-form-item>
      </el-form>
      <el-alert v-if="profileError" :title="profileError" type="error" :closable="false" style="margin-bottom: 16px" />
      <el-button type="primary" :loading="savingProfile" @click="handleSaveProfile">儲存</el-button>
    </el-card>

    <el-card class="profile__card">
      <template #header>修改密碼</template>
      <el-form label-width="90px" style="max-width: 420px">
        <el-form-item label="目前密碼">
          <el-input v-model="passwordForm.current_password" type="password" show-password />
        </el-form-item>
        <el-form-item label="新密碼">
          <el-input v-model="passwordForm.password" type="password" show-password />
        </el-form-item>
        <el-form-item label="確認新密碼">
          <el-input v-model="passwordForm.password_confirm" type="password" show-password />
        </el-form-item>
      </el-form>
      <el-alert v-if="passwordError" :title="passwordError" type="error" :closable="false" style="margin-bottom: 16px" />
      <el-button type="primary" :loading="savingPassword" @click="handleChangePassword">修改密碼</el-button>
    </el-card>
  </div>
</template>

<script setup>
/* *****************************************
File: Profile.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 個人資料編輯與修改密碼頁面，見 README 7 節。
***************************************** */

import { onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { authFetch } from '../stores/auth';

const username = ref('');
const profileForm = reactive({ name: '', email: '' });
const profileLoadError = ref('');
const profileError = ref('');
const savingProfile = ref(false);

const passwordForm = reactive({ current_password: '', password: '', password_confirm: '' });
const passwordError = ref('');
const savingPassword = ref(false);

async function loadProfile() {
  profileLoadError.value = '';

  const res = await authFetch('/api/user/profile');
  const body = await res.json();

  if (!body.status) {
    profileLoadError.value = body.error_message || '載入個人資料失敗';

    return;
  }

  username.value = body.data.username;
  profileForm.name = body.data.name;
  profileForm.email = body.data.email || '';
}

async function handleSaveProfile() {
  profileError.value = '';
  savingProfile.value = true;

  try {
    const res = await authFetch('/api/user/profile', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: profileForm.name, email: profileForm.email || null }),
    });
    const body = await res.json();

    if (!body.status) {
      profileError.value = body.error_message || '儲存失敗';

      return;
    }

    ElMessage.success('已儲存');
  } finally {
    savingProfile.value = false;
  }
}

async function handleChangePassword() {
  passwordError.value = '';

  if (passwordForm.password !== passwordForm.password_confirm) {
    passwordError.value = '兩次輸入的新密碼不一致';

    return;
  }

  savingPassword.value = true;

  try {
    const res = await authFetch('/api/user/password', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ current_password: passwordForm.current_password, password: passwordForm.password }),
    });
    const body = await res.json();

    if (!body.status) {
      passwordError.value = body.error_message || '修改密碼失敗';

      return;
    }

    passwordForm.current_password = '';
    passwordForm.password = '';
    passwordForm.password_confirm = '';
    ElMessage.success('密碼已修改');
  } finally {
    savingPassword.value = false;
  }
}

onMounted(loadProfile);
</script>

<style scoped>
.profile {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 500px;
}
</style>
