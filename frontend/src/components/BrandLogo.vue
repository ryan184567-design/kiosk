<template>
  <div class="brand">
    <span class="brand__mark">NSST</span>
    <span class="brand__text">
      <span class="brand__name">新保科技</span>
      <span class="brand__slogan">智慧建築・美好生活</span>
    </span>
  </div>
</template>

<style scoped>
.brand {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  line-height: 1;
}
.brand__mark {
  font-size: 30px;
  font-weight: 900;
  letter-spacing: -0.5px;
  color: var(--brand-dark);
  font-family: 'Arial Black', 'Segoe UI', sans-serif;
}
.brand__text {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.brand__name {
  font-size: 17px;
  font-weight: 700;
  color: var(--brand-dark);
}
.brand__slogan {
  font-size: 10px;
  color: var(--muted);
  letter-spacing: 1px;
}
</style>
