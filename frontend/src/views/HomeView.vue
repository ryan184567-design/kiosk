<script setup>
// ① 首頁：選擇到訪方式（有預約 / 無預約 / 離場）與語系
import { useRouter } from 'vue-router'
import { LogoutOutlined, QrcodeOutlined, UserOutlined } from '@ant-design/icons-vue'
import KioskLayout from '@/components/KioskLayout.vue'
import { resetVisit, visit } from '@/stores/visit'
import { LOCALES, currentLocale, setLocale } from '@/i18n'
import { debug } from '@/utils/logger'

const router = useRouter()

const MODES = {
  appointment: { label: '有預約', path: '/appointment' },
  walkIn: { label: '無預約', path: '/walk-in' },
  checkOut: { label: '離場', path: '/check-out' },
}

const start = (mode) => {
  resetVisit()
  visit.mode = mode
  debug('Flow', `① 選擇到訪方式：${MODES[mode].label}`)
  router.push(MODES[mode].path)
}
</script>

<template>
  <KioskLayout>
    <div class="home">
      <h1 class="home__welcome">{{ $t('home.welcome') }}</h1>
      <p class="home__choose">{{ $t('home.choose') }}</p>

      <div class="home__options">
        <button class="option option--primary" @click="start('appointment')">
          <QrcodeOutlined class="option__icon" />
          <span class="option__title">{{ $t('home.appointment') }}</span>
          <span class="option__desc">{{ $t('home.appointmentDesc') }}</span>
        </button>
        <button class="option option--secondary" @click="start('walkIn')">
          <UserOutlined class="option__icon" />
          <span class="option__title">{{ $t('home.walkIn') }}</span>
          <span class="option__desc">{{ $t('home.walkInDesc') }}</span>
        </button>
        <button class="option option--secondary" @click="start('checkOut')">
          <LogoutOutlined class="option__icon" />
          <span class="option__title">{{ $t('home.checkOut') }}</span>
          <span class="option__desc">{{ $t('home.checkOutDesc') }}</span>
        </button>
      </div>
    </div>

    <template #footer>
      <div class="lang">
        <button
          v-for="l in LOCALES"
          :key="l.value"
          class="lang__btn"
          :class="{ 'is-active': currentLocale === l.value }"
          @click="setLocale(l.value)"
        >
          {{ l.label }}
        </button>
      </div>
    </template>
  </KioskLayout>
</template>

<style scoped>
.home {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}
.home__welcome {
  margin: 0;
  font-size: clamp(44px, 6vw, 76px);
  font-weight: 900;
  letter-spacing: 6px;
  color: var(--brand-dark);
}
.home__choose {
  margin: 8px 0 48px;
  font-size: clamp(20px, 2.4vw, 30px);
  font-weight: 700;
  color: var(--text);
}
.home__options {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: clamp(20px, 3vw, 40px);
}
.option {
  width: clamp(220px, 26vw, 340px);
  aspect-ratio: 1 / 0.9;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 24px;
  border: none;
  border-radius: 24px;
  cursor: pointer;
  transition:
    transform 0.15s,
    box-shadow 0.15s;
}
.option:active {
  transform: scale(0.97);
}
.option--primary {
  color: #fff;
  background: var(--brand-gradient);
  box-shadow: 0 18px 44px rgba(21, 84, 192, 0.38);
}
.option--secondary {
  color: var(--text);
  background: #fff;
  border: 1px solid var(--line);
  box-shadow: var(--shadow);
}
.option__icon {
  font-size: clamp(64px, 7vw, 92px);
  margin-bottom: 8px;
}
.option--secondary .option__icon {
  color: var(--brand);
}.option__title {
  font-size: clamp(26px, 2.8vw, 34px);
  font-weight: 800;
}
.option__desc {
  font-size: clamp(15px, 1.5vw, 18px);
  white-space: pre-line;
  opacity: 0.9;
}

.lang {
  display: flex;
  gap: 12px;
}
.lang__btn {
  min-width: 120px;
  height: 48px;
  padding: 0 20px;
  font-size: 18px;
  color: var(--text);
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 10px;
  cursor: pointer;
}
@media (max-width: 600px) {
  .lang {
    gap: 8px;
  }
  .lang__btn {
    min-width: 0;
    padding: 0 16px;
    font-size: 16px;
  }
}
.lang__btn.is-active {
  color: #fff;
  background: var(--brand);
  border-color: var(--brand);
  font-weight: 700;
}
</style>
