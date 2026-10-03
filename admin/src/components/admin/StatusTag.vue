<script setup lang="ts">
import { computed } from 'vue'
import { STATUS_META, TONE_COLORS, type UserStatus } from '@/types/user'

/**
 * 状态徽章（正常 / 禁言 / 封禁）。
 * 和 RoleTag 是一对：配色共用 types/user.ts 里的 TONE_COLORS。
 */
const props = defineProps<{ status: UserStatus }>()

const meta = computed(() => STATUS_META[props.status])
</script>

<template>
  <n-tag size="small" :bordered="false" :color="TONE_COLORS[meta.tone]" class="status-tag">
    {{ meta.label }}
  </n-tag>
</template>

<style scoped>
.status-tag {
  font-weight: 600;
  letter-spacing: 0.06em;
}
</style>
