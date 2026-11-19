<script setup>
import { CheckOutlined, CloseOutlined, SendOutlined } from '@ant-design/icons-vue'

defineProps({
  /** loading | success | error | sent */
  status: { type: String, default: 'loading' },
  title: { type: String, default: '' },
  hint: { type: String, default: '' },
  /** 緊湊模式：較小的圖示與留白（用於上方還有其他內容的頁面） */
  compact: { type: Boolean, default: false },
})
</script>

<template>
  <div class="status panel" :class="{ 'status--compact': compact }">
    <div class="status__icon">
      <slot name="icon">
        <div v-if="status === 'loading'" class="spinner" />
        <div v-else-if="status === 'error'" class="circle circle--error"><CloseOutlined /></div>
        <div v-else-if="status === 'sent'" class="circle"><SendOutlined /></div>
        <div v-else class="circle"><CheckOutlined /></div>
      </slot>
    </div>
    <h2 class="status__title">{{ title }}</h2>
    <p v-if="hint" class="status__hint">{{ hint }}</p>
    <div v-if="$slots.default" class="status__extra">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.status {
  width: min(720px, 100%);
  min-height: 460px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 32px;
  box-sizing: border-box;
  text-align: center;
}
.status__icon {
  margin-bottom: 36px;
}
.status__title {
  margin: 0;
  font-size: clamp(26px, 3vw, 36px);
  font-weight: 800;
  color: var(--brand-dark);
}
.status__hint {
  margin: 14px 0 0;
  font-size: 20px;
  line-height: 1.7;
  color: var(--muted);
  white-space: pre-line;
}
.status__extra {
  margin-top: 32px;
}

.spinner {
  width: 150px;
  height: 150px;
  border-radius: 50%;
  border: 14px solid #dce8fb;
  border-top-color: var(--brand);
  border-right-color: #3a8bf5;
  box-sizing: border-box;
  animation: spin 1s linear infinite;
}
.circle {
  width: 160px;
  height: 160px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: 76px;
  color: #fff;
  background: var(--brand-gradient);
  box-shadow: 0 16px 40px rgba(21, 84, 192, 0.35);
  animation: pop 0.45s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.circle--error {
  background: linear-gradient(160deg, #ff7875, #d9363e);
  box-shadow: 0 16px 40px rgba(217, 54, 62, 0.3);
}

.status--compact {
  min-height: 0;
  padding: 32px;
}
.status--compact .status__icon {
  margin-bottom: 20px;
}
.status--compact .circle {
  width: 112px;
  height: 112px;
  font-size: 52px;
}
.status--compact .status__extra {
  margin-top: 20px;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
@keyframes pop {
  from {
    transform: scale(0.4);
    opacity: 0;
  }
  to {
    transform: scale(1);
    opacity: 1;
  }
}
</style>
