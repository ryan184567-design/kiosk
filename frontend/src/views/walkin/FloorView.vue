<script setup>
// 無預約 ② 選擇到訪樓層
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import KioskLayout from '@/components/KioskLayout.vue'
import WalkInSteps from '@/components/WalkInSteps.vue'
import { getFloors } from '@/api/visitor'
import { visit } from '@/stores/visit'
import { debug } from '@/utils/logger'

const router = useRouter()
const floors = ref([])
const loading = ref(true)

onMounted(async () => {
  try {
    floors.value = await getFloors()
  } finally {
    loading.value = false
  }
})

const select = (floor) => {
  if (visit.walkIn.floor?.id !== floor.id) visit.walkIn.company = null
  visit.walkIn.floor = floor
  debug('Flow', `② 無預約：選擇樓層 ${floor.label}`)
  router.push('/walk-in/company')
}
</script>

<template>
  <KioskLayout back="/" :title="$t('walkIn.floorTitle')">
    <WalkInSteps :current="0" />
    <a-spin :spinning="loading">
      <div class="floors">
        <button
          v-for="f in floors"
          :key="f.id"
          class="floor"
          :class="{ 'is-active': visit.walkIn.floor?.id === f.id }"
          @click="select(f)"
        >
          {{ f.label }}
        </button>
      </div>
    </a-spin>
  </KioskLayout>
</template>

<style scoped>
.floors {
  width: min(720px, calc(100vw - 32px));
  min-height: 200px;
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
}
.floor {
  height: 84px;
  font-size: 28px;
  font-weight: 700;
  color: var(--text);
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
  box-shadow: 0 6px 16px rgba(21, 84, 192, 0.08);
  cursor: pointer;
  transition:
    transform 0.1s,
    background 0.15s;
}
.floor:active {
  transform: scale(0.96);
}
.floor.is-active {
  color: #fff;
  background: var(--brand-gradient);
  border-color: transparent;
  box-shadow: 0 10px 24px rgba(21, 84, 192, 0.35);
}
@media (max-width: 600px) {
  .floor {
    height: 64px;
    font-size: 22px;
  }
}
</style>
