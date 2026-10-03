<script setup lang="ts">
import { computed } from 'vue'
import RoleTag from './RoleTag.vue'
import { hueFromId, initialOf } from '@/utils/publicId'
import type { UserRecord } from '@/types/user'

/**
 * 用户的统一展示块：头像 + 昵称 + 公开 ID + 身份徽章。
 *
 * 全站凡是"出现一个人"的地方（用户列表、文章作者、帖子楼主、审计日志的操作人）
 * 都用它，形状和字号才是一致的 —— 这也是最初提的"前端显示时要统一风格"。
 *
 * 头像暂时没有上传功能，所以用昵称首字 + 由公开 ID 派生的渐变底色兜底：
 * 同一个人每次刷新颜色都一样，不同的人颜色不同，既统一又能一眼区分。
 * 将来后端给了 avatar 地址，传进来就直接用图片，其余逻辑不用改。
 */
const props = withDefaults(
  defineProps<{
    user: UserRecord
    size?: 'sm' | 'md' | 'lg'
    /** 表格里横向紧张时可以关掉 ID 那行 */
    showId?: boolean
    showRole?: boolean
  }>(),
  { size: 'md', showId: true, showRole: true },
)

const AVATAR_SIZE: Record<'sm' | 'md' | 'lg', number> = { sm: 28, md: 34, lg: 44 }

const avatarSize = computed(() => AVATAR_SIZE[props.size])
const initial = computed(() => initialOf(props.user.nickname))

const avatarStyle = computed(() => {
  if (props.user.avatar) return undefined
  const hue = hueFromId(props.user.publicId)
  return {
    background: `linear-gradient(145deg, hsl(${hue}, 62%, 48%), hsl(${(hue + 42) % 360}, 58%, 31%))`,
    color: '#f4f8ff',
  }
})
</script>

<template>
  <div class="identity" :class="`is-${size}`">
    <n-avatar round :size="avatarSize" :src="user.avatar" :style="avatarStyle" class="avatar">
      {{ user.avatar ? '' : initial }}
    </n-avatar>

    <div class="meta">
      <div class="row-main">
        <span class="nickname">{{ user.nickname }}</span>
        <RoleTag v-if="showRole" :role="user.role" />
      </div>

      <div v-if="showId" class="row-sub">
        <span class="public-id">{{ user.publicId }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.identity {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.avatar {
  flex: none;
  font-weight: 600;
  letter-spacing: 0;
}

.meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.row-main {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.nickname {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #e9ecf5;
  font-weight: 600;
  letter-spacing: 0.02em;
}

/* 公开 ID 用等宽字体：它是一串编号，等宽才不会在不同行之间跳动 */
.public-id {
  font-family: ui-monospace, 'SFMono-Regular', 'JetBrains Mono', Consolas, monospace;
  font-variant-numeric: tabular-nums;
  font-size: 11px;
  letter-spacing: 0.04em;
  color: rgba(124, 132, 155, 0.95);
}

/* 尺寸档：只调字号，结构不动 */
.is-sm .nickname {
  font-size: 12.5px;
}

.is-sm .public-id {
  font-size: 10.5px;
}

.is-md .nickname {
  font-size: 13.5px;
}

.is-lg .nickname {
  font-size: 15px;
}

.is-lg .public-id {
  font-size: 12px;
}
</style>
