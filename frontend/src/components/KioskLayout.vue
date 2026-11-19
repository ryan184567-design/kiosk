<script setup>
import { useRouter } from 'vue-router'
import { LeftOutlined } from '@ant-design/icons-vue'
import BrandLogo from './BrandLogo.vue'
import { useClock } from '@/composables/useClock'

const props = defineProps({
  /** 返回按鈕要前往的路徑；不傳則顯示 Logo */
  back: { type: String, default: '' },
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
})

const router = useRouter()
const clock = useClock()

const onBack = () => router.replace(props.back)
</script>

<template>
  <div class="kiosk">
    <header class="kiosk__header">
      <button v-if="back" class="kiosk__back" @click="onBack">
        <LeftOutlined />
        <span>{{ $t('common.back') }}</span>
      </button>
      <BrandLogo v-else />
      <time class="kiosk__clock">{{ clock }}</time>
    </header>

    <main class="kiosk__body">
      <div v-if="title" class="kiosk__heading">
        <h1>{{ title }}</h1>
        <p v-if="subtitle">{{ subtitle }}</p>
      </div>
      <slot />
    </main>

    <footer v-if="$slots.footer" class="kiosk__footer">
      <slot name="footer" />
    </footer>
  </div>
</template>

<style scoped>
.kiosk {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  padding: 0 clamp(16px, 4vw, 56px);
  box-sizing: border-box;
}
.kiosk__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 84px;
  flex-shrink: 0;
}
.kiosk__back {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 48px;
  padding: 0 22px;
  font-size: 18px;
  color: var(--text);
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  box-shadow: 0 4px 12px rgba(21, 84, 192, 0.08);
  cursor: pointer;
}
.kiosk__back:active {
  transform: scale(0.97);
}
.kiosk__clock {
  font-size: 18px;
  font-weight: 600;
  color: var(--text);
  font-variant-numeric: tabular-nums;
}
.kiosk__body {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 16px 0 32px;
}
.kiosk__heading {
  text-align: center;
  margin-bottom: 24px;
}
.kiosk__heading h1 {
  margin: 0;
  font-size: clamp(28px, 3.4vw, 40px);
  font-weight: 800;
  color: var(--brand-dark);
}
.kiosk__heading p {
  margin: 8px 0 0;
  font-size: clamp(16px, 1.6vw, 20px);
  color: var(--muted);
}
.kiosk__footer {
  display: flex;
  justify-content: center;
  padding-bottom: 32px;
}
.kiosk__clock {
  white-space: nowrap;
}
@media (max-width: 600px) {
  .kiosk__header {
    height: 64px;
  }
  .kiosk__clock {
    font-size: 14px;
  }
  .kiosk__header :deep(.brand__slogan) {
    display: none;
  }
}
</style>
