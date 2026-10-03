<script setup lang="ts">
import { computed } from 'vue'
import { findRole, roleToneOf } from '@/mock/roles'
import { TONE_COLORS, type UserRole } from '@/types/user'

/**
 * 角色徽章。
 *
 * 名字和配色都去 mock/roles.ts 里查，而不是读一张写死的表 ——
 * 角色可以在界面上自定义（"论坛管理员 A" / "论坛管理员 B"），
 * 徽章必须跟着变。查不到时兜底成"未知身份" + 灰色，
 * 不至于因为一条脏数据把整个列表渲染崩掉。
 *
 * 用 n-tag 的 `color` 属性拿精确配色，而不是覆写它的 --n-* 变量 ——
 * 前者是官方给定的定制入口，后者要动一堆内部节点、升级容易碎。
 */
const props = defineProps<{ role: UserRole; size?: 'small' | 'medium' }>()

const name = computed(() => findRole(props.role)?.name ?? '未知身份')
const tone = computed(() => roleToneOf(props.role))
</script>

<template>
  <n-tag :size="size ?? 'small'" :bordered="false" :color="TONE_COLORS[tone]" class="role-tag">
    {{ name }}
  </n-tag>
</template>

<style scoped>
.role-tag {
  font-weight: 600;
  letter-spacing: 0.06em;
}
</style>
