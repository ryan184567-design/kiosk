<script setup>
// 無預約 ③ 選擇受訪公司
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { RightOutlined, SearchOutlined } from '@ant-design/icons-vue'
import KioskLayout from '@/components/KioskLayout.vue'
import WalkInSteps from '@/components/WalkInSteps.vue'
import { getCompanies } from '@/api/visitor'
import { visit } from '@/stores/visit'
import { debug } from '@/utils/logger'

const router = useRouter()
const companies = ref([])
const loading = ref(true)
const keyword = ref('')

const filtered = computed(() => {
  const k = keyword.value.trim().toLowerCase()
  return k ? companies.value.filter((c) => c.name.toLowerCase().includes(k)) : companies.value
})

onMounted(async () => {
  try {
    companies.value = await getCompanies(visit.walkIn.floor.id)
  } finally {
    loading.value = false
  }
})

const select = (company) => {
  visit.walkIn.company = company
  debug('Flow', `③ 無預約：選擇受訪公司 ${company.name}`, company)
  router.push('/walk-in/form')
}
</script>

<template>
  <KioskLayout
    back="/walk-in"
    :title="$t('walkIn.companyTitle')"
    :subtitle="visit.walkIn.floor?.label"
  >
    <WalkInSteps :current="1" />
    <div class="company">
      <a-input
        v-model:value="keyword"
        size="large"
        allow-clear
        class="company__search"
        :placeholder="$t('walkIn.searchPlaceholder')"
      >
        <template #suffix><SearchOutlined /></template>
      </a-input>

      <a-spin :spinning="loading">
        <ul class="company__list panel">
          <li
            v-for="c in filtered"
            :key="c.id"
            class="company__item"
            :class="{ 'is-active': visit.walkIn.company?.id === c.id }"
            @click="select(c)"
          >
            <span>{{ c.name }}</span>
            <RightOutlined />
          </li>
          <li v-if="!loading && !filtered.length" class="company__empty">
            {{ $t('walkIn.noCompany') }}
          </li>
        </ul>
      </a-spin>
    </div>
  </KioskLayout>
</template>

<style scoped>
.company {
  width: min(720px, 100%);
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.company__search :deep(input) {
  font-size: 20px;
}
.company__search.ant-input-affix-wrapper {
  height: 60px;
  border-radius: 12px;
}
.company__list {
  margin: 0;
  padding: 0;
  list-style: none;
  overflow: hidden;
  min-height: 120px;
}
.company__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 68px;
  padding: 0 24px;
  font-size: 20px;
  border-bottom: 1px solid var(--line);
  cursor: pointer;
  transition: background 0.15s;
}
.company__item:last-child {
  border-bottom: none;
}
.company__item:active {
  background: var(--brand-light);
}
.company__item.is-active {
  color: #fff;
  background: var(--brand);
}
.company__empty {
  padding: 40px 0;
  text-align: center;
  font-size: 18px;
  color: var(--muted);
}
</style>
