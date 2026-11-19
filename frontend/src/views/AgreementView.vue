<script setup>
// ⑥ 簽署協議
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import KioskLayout from '@/components/KioskLayout.vue'
import { clearStepsFrom, needsLocker, visit } from '@/stores/visit'
import { debug } from '@/utils/logger'

const router = useRouter()
// 每次進入都需重新勾選同意
clearStepsFrom('agreement')
const agreed = ref(false)
const TERMS = ['term1', 'term2', 'term3', 'term4']
// 有證件存放時返回重新選擇格位，否則回到身份驗證
const backPath = needsLocker() ? '/locker' : '/identity'

const confirm = () => {
  debug('Flow', '⑥ 訪客已同意條款')
  visit.agreed = true
  router.push('/printing')
}
</script>

<template>
  <KioskLayout :back="backPath" :title="$t('agreement.title')">
    <div class="agreement">
      <ol class="agreement__terms panel">
        <li v-for="k in TERMS" :key="k">{{ $t(`agreement.${k}`) }}</li>
      </ol>

      <label class="agreement__check">
        <a-checkbox v-model:checked="agreed" />
        <span>{{ $t('agreement.agree') }}</span>
      </label>

      <a-button
        type="primary"
        size="large"
        block
        class="kiosk-btn"
        :disabled="!agreed"
        @click="confirm"
      >
        {{ $t('common.confirm') }}
      </a-button>
    </div>
  </KioskLayout>
</template>

<style scoped>
.agreement {
  width: min(820px, 100%);
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 24px;
}
.agreement__terms {
  margin: 0;
  padding: 32px 40px 32px 64px;
  font-size: 20px;
  line-height: 1.9;
}
.agreement__check {
  display: flex;
  align-items: center;
  gap: 14px;
  font-size: 20px;
  font-weight: 600;
  cursor: pointer;
  padding: 0 8px;
}
.agreement__check :deep(.ant-checkbox-inner) {
  width: 30px;
  height: 30px;
}
.agreement__check :deep(.ant-checkbox-inner::after) {
  width: 9px;
  height: 16px;
}
</style>
