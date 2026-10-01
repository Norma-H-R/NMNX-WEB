<script setup>
const year = new Date().getFullYear()
</script>

<template>
  <footer class="ftr">
    <div class="container ftr__inner">
      <div class="ftr__brand">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path
            d="M12 1.6c.6 4.9 1.7 6 6.6 6.6-4.9.6-6 1.7-6.6 6.6-.6-4.9-1.7-6-6.6-6.6 4.9-.6 6-1.7 6.6-6.6Z"
            fill="currentColor"
          />
        </svg>
        <span>南门拈星</span>
      </div>

      <p class="ftr__note">
        本站内容仅为技术介绍，不构成任何投资建议。交易有风险，过往表现不代表未来收益。
      </p>

      <p class="ftr__copy">© {{ year }} 南门拈星</p>
    </div>
  </footer>
</template>

<style scoped>
.ftr {
  position: relative;
  z-index: 1;
  border-top: 1px solid var(--line);
  padding: 52px 0 44px;
}

.ftr__inner {
  display: grid;
  gap: 20px;
}

.ftr__brand {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  font-size: 15px;
  letter-spacing: 0.16em;
  color: var(--text-dim);
}

.ftr__brand svg {
  width: 18px;
  height: 18px;
  color: var(--cyan);
}

.ftr__note {
  max-width: 62ch;
  font-size: 12.5px;
  line-height: 1.95;
  color: var(--muted);
}

.ftr__copy {
  font-size: 12.5px;
  letter-spacing: 0.1em;
  color: #5a6178;
}
</style>
