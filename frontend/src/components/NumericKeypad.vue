<script setup>
import { computed } from 'vue'

const model = defineModel({ type: String, default: '' })
const props = defineProps({
  label: { type: String, default: '' },
  maxLength: { type: Number, default: 10 },
  placeholder: { type: String, default: '' },
})
const emit = defineEmits(['confirm'])

const DIGITS = ['1', '2', '3', '4', '5', '6', '7', '8', '9']

// 每 5 碼以空白分隔，方便核對，例：12345 67890
const display = computed(() => model.value.replace(/(\d{5})(?=\d)/g, '$1 '))

const press = (d) => {
  if (model.value.length < props.maxLength) model.value += d
}
const clear = () => (model.value = '')
const backspace = () => (model.value = model.value.slice(0, -1))
</script>

<template>
  <div class="keypad">
    <div class="keypad__label">{{ label }}</div>
    <div class="keypad__display" :class="{ 'is-empty': !model }">
      {{ display || placeholder }}
    </div>
    <div class="keypad__grid">
      <button v-for="d in DIGITS" :key="d" class="key" @click="press(d)">{{ d }}</button>
      <button class="key key--fn" @click="clear">{{ $t('scan.clear') }}</button>
      <button class="key" @click="press('0')">0</button>
      <button class="key key--fn" aria-label="backspace" @click="backspace">⌫</button>
    </div>
    <a-button
      type="primary"
      size="large"
      block
      class="kiosk-btn"
      :disabled="!model"
      @click="emit('confirm')"
    >
      {{ $t('common.confirm') }}
    </a-button>
  </div>
</template>

<style scoped>
.keypad {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.keypad__label {
  text-align: center;
  font-size: 18px;
  font-weight: 600;
}
.keypad__display {
  height: 60px;
  display: grid;
  place-items: center;
  font-size: 30px;
  font-weight: 700;
  letter-spacing: 2px;
  font-variant-numeric: tabular-nums;
  background: var(--brand-light);
  border-radius: 12px;
  color: var(--brand-dark);
}
.keypad__display.is-empty {
  color: #a9b8d0;
}
.keypad__grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}
.key {
  height: 62px;
  font-size: 26px;
  font-weight: 600;
  color: var(--text);
  background: #f4f8ff;
  border: 1px solid var(--line);
  border-radius: 12px;
  cursor: pointer;
  transition:
    background 0.15s,
    transform 0.1s;
}
.key:active {
  background: #dbe8fd;
  transform: scale(0.96);
}
.key--fn {
  font-size: 20px;
  color: var(--muted);
}
</style>
