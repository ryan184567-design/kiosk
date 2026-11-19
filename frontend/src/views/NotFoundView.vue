<script setup>
import { useRouter } from 'vue-router'
import KioskLayout from '@/components/KioskLayout.vue'

const router = useRouter()
</script>

<template>
  <KioskLayout>
    <a-result status="404" title="404" :sub-title="$t('notFound.title')">
      <template #extra>
        <a-button type="primary" size="large" class="kiosk-btn" @click="router.replace('/')">
          {{ $t('common.backHome') }}
        </a-button>
      </template>
    </a-result>
  </KioskLayout>
</template>
