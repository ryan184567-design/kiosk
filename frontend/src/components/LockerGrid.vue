<script setup>
// 證件存放格位圖（訪客螢幕使用）：依 API 提供的欄數與格位排列，只能點選可使用的格位
import { useI18n } from 'vue-i18n'

const selected = defineModel('selected', { type: String, default: null })

defineProps({
  columns: { type: Number, default: 6 },
  /** [{ id, label, status: 'available' | 'occupied' | 'disabled' }] */
  slots: { type: Array, default: () => [] },
})

const { t } = useI18n()
const STATUS_TEXT = (k) => t(`locker.status.${k}`)
const LEGEND = ['available', 'occupied', 'disabled', 'selected']

const select = (slot) => {
  if (slot.status === 'available') selected.value = slot.id
}
</script>

<template>
  <div class="locker-grid">
    <div class="legend">
      <span v-for="k in LEGEND" :key="k" class="legend__item">
        <i class="legend__dot" :class="`is-${k}`" />{{ STATUS_TEXT(k) }}
      </span>
    </div>
    <div class="cabinet" :style="{ gridTemplateColumns: `repeat(${columns}, 1fr)` }">
      <button
        v-for="s in slots"
        :key="s.id"
        class="slot"
        :class="[`is-${s.status}`, { 'is-selected': s.id === selected }]"
        :disabled="s.status !== 'available'"
        @click="select(s)"
      >
        <span class="slot__label">{{ s.label }}</span>
        <span v-if="s.status !== 'available'" class="slot__status">{{ STATUS_TEXT(s.status) }}</span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.locker-grid {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  font-size: 15px;
  color: var(--muted);
}
.legend__item {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.legend__dot {
  width: 16px;
  height: 16px;
  border-radius: 4px;
  border: 1px solid var(--line);
}
.cabinet {
  display: grid;
  gap: 10px;
}
.slot {
  height: 68px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  border-radius: 10px;
  border: 2px solid var(--line);
  cursor: pointer;
  transition:
    transform 0.1s,
    background 0.15s;
}
.slot__label {
  font-size: 20px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}
.slot__status {
  font-size: 12px;
}
.slot:not(:disabled):active {
  transform: scale(0.95);
}
.is-available {
  color: var(--brand-dark);
  background: #f4f8ff;
  border-color: #bcd3f5;
}
.is-occupied {
  color: #9aa6b8;
  background: #eef0f4;
  border-color: #e1e5eb;
  cursor: not-allowed;
}
.is-disabled {
  color: #b5bcc8;
  background: repeating-linear-gradient(-45deg, #f3f4f6, #f3f4f6 6px, #e7e9ee 6px, #e7e9ee 12px);
  border-color: #e1e5eb;
  cursor: not-allowed;
}
.is-selected {
  color: #fff;
  background: var(--brand-gradient);
  border-color: transparent;
  box-shadow: 0 8px 20px rgba(21, 84, 192, 0.35);
}
</style>
