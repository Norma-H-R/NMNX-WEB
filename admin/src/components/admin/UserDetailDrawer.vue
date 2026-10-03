<script setup lang="ts">
import { computed } from 'vue'
import UserIdentity from './UserIdentity.vue'
import StatusTag from './StatusTag.vue'
import { getUserDetail } from '@/mock/users'
import { permissionGroups } from '@/mock/permissions'
import { roleNameOf } from '@/mock/roles'
import {
  ACTIVITY_META,
  LICENSE_STATUS_META,
  TONE_COLORS,
  type PermissionGroup,
} from '@/types/user'

/**
 * 用户详情抽屉。
 *
 * 为什么用抽屉而不是弹窗 / 独立页面：
 *   一个用户要看的东西太多了 —— 基础资料、名下授权、绑定的机器、权限清单、
 *   操作与登录轨迹。弹窗装不下（要么内部滚动条套滚动条，要么高度撑爆），
 *   独立页面又会让"看一眼再回来"变成两次跳转。右侧宽抽屉刚好：**不离开列表**，
 *   又能给足空间。宽度给到 960px，接近半屏。
 *
 * 面板结构是"可增长"的：现在四块（资料 / 授权 / 权限 / 动态），
 * 以后加"发帖记录""工单"之类，往中间插一个 <section> 就行。
 */
const props = defineProps<{
  show: boolean
  /** 要看谁；null 表示没选中 */
  userId: number | null
}>()

const emit = defineEmits<{ 'update:show': [value: boolean] }>()

const detail = computed(() => (props.userId === null ? null : getUserDetail(props.userId)))

/**
 * 只列出"这个人真正拥有的"权限组，组内也只留已授予的项 ——
 * 64 个权限全铺出来、把没有的划掉，读的人得自己筛一遍，很累。
 */
const grantedGroups = computed<PermissionGroup[]>(() => {
  const user = detail.value
  if (!user) return []
  const owned = new Set(user.permissions)

  return permissionGroups.map((group) => ({
    title: group.title,
    items: group.items.filter((item) => owned.has(item.key)),
  })).filter((group) => group.items.length > 0)
})

const isOwner = computed(() => detail.value?.role === 'owner')
</script>

<template>
  <n-drawer
    :show="show"
    placement="right"
    width="min(960px, 94vw)"
    :auto-focus="false"
    @update:show="emit('update:show', $event)"
  >
    <n-drawer-content v-if="detail" :native-scrollbar="false" closable>
      <!-- ── 头部：这个人是谁 ─────────────────────────────────────── -->
      <template #header>
        <div class="head">
          <UserIdentity :user="detail" size="lg" />
          <StatusTag :status="detail.status" />
        </div>
      </template>

      <div class="body">
        <!-- ── 基础资料 ───────────────────────────────────────────── -->
        <section class="section">
          <h3 class="section-title">基础资料</h3>
          <div class="facts">
            <div class="fact">
              <span class="fact-key">邮箱</span>
              <span class="fact-value">{{ detail.email }}</span>
            </div>
            <div class="fact">
              <span class="fact-key">手机</span>
              <span class="fact-value">{{ detail.phone ?? '未绑定' }}</span>
            </div>
            <div class="fact">
              <span class="fact-key">身份</span>
              <span class="fact-value">{{ roleNameOf(detail.role) }}</span>
            </div>
            <div class="fact">
              <span class="fact-key">注册时间</span>
              <span class="fact-value">{{ detail.registeredAt }}</span>
            </div>
            <div class="fact">
              <span class="fact-key">最后活跃</span>
              <span class="fact-value">{{ detail.lastSeenAt }}</span>
            </div>
          </div>
          <p v-if="detail.bio" class="bio">{{ detail.bio }}</p>
        </section>

        <!-- ── 交易资产 ───────────────────────────────────────────── -->
        <section class="section">
          <h3 class="section-title">
            交易资产
            <span class="section-hint">{{ detail.licenses.length }} 个授权</span>
          </h3>

          <div v-if="detail.licenses.length" class="licenses">
            <article v-for="license in detail.licenses" :key="license.code" class="license">
              <header class="license-head">
                <span class="license-code">{{ license.code }}</span>
                <n-tag
                  size="small"
                  :bordered="false"
                  :color="TONE_COLORS[LICENSE_STATUS_META[license.status].tone]"
                >
                  {{ LICENSE_STATUS_META[license.status].label }}
                </n-tag>
              </header>

              <div class="license-meta">
                <div class="meta-cell">
                  <span class="fact-key">产品版本</span>
                  <span class="fact-value">{{ license.product }} · {{ license.edition }}</span>
                </div>
                <div class="meta-cell">
                  <span class="fact-key">绑定机器</span>
                  <span class="fact-value mono">{{ license.machineId }}</span>
                </div>
                <div class="meta-cell">
                  <span class="fact-key">绑定时间</span>
                  <span class="fact-value">{{ license.boundAt }}</span>
                </div>
                <div class="meta-cell">
                  <span class="fact-key">到期时间</span>
                  <span class="fact-value">{{ license.expireAt }}</span>
                </div>
              </div>
            </article>
          </div>

          <p v-else class="empty">该用户名下没有授权记录</p>
        </section>

        <!-- ── 权限 ───────────────────────────────────────────────── -->
        <section class="section">
          <h3 class="section-title">
            权限
            <span class="section-hint">
              {{ isOwner ? '全部' : `${detail.permissions.length} 项` }}
            </span>
          </h3>

          <p v-if="isOwner" class="empty">
            超级管理员隐含全部权限，不单独配置
          </p>

          <div v-else-if="grantedGroups.length" class="perm-blocks">
            <div v-for="group in grantedGroups" :key="group.title" class="perm-block">
              <p class="perm-block-title">{{ group.title }}</p>
              <div class="perm-tags">
                <n-tag
                  v-for="item in group.items"
                  :key="item.key"
                  size="small"
                  :bordered="false"
                  :color="TONE_COLORS.cyan"
                >
                  {{ item.label }}
                </n-tag>
              </div>
            </div>
          </div>

          <p v-else class="empty">该用户没有任何后台权限</p>
        </section>

        <!-- ── 近期动态 ───────────────────────────────────────────── -->
        <section class="section">
          <h3 class="section-title">近期动态</h3>

          <ol class="timeline">
            <li v-for="item in detail.activity" :key="item.id" class="tl-item">
              <span
                class="tl-dot"
                :style="{ background: TONE_COLORS[ACTIVITY_META[item.kind].tone].textColor }"
                aria-hidden="true"
              />
              <div class="tl-body">
                <div class="tl-head">
                  <span class="tl-title">{{ item.title }}</span>
                  <n-tag
                    size="tiny"
                    :bordered="false"
                    :color="TONE_COLORS[ACTIVITY_META[item.kind].tone]"
                  >
                    {{ ACTIVITY_META[item.kind].label }}
                  </n-tag>
                </div>
                <p v-if="item.detail" class="tl-detail">{{ item.detail }}</p>
                <p class="tl-meta mono">{{ item.at }} · {{ item.ip }}</p>
              </div>
            </li>
          </ol>
        </section>
      </div>
    </n-drawer-content>
  </n-drawer>
</template>

<style scoped>
.head {
  display: flex;
  align-items: center;
  gap: 12px;
}

.body {
  display: flex;
  flex-direction: column;
  gap: 26px;
  padding-bottom: 12px;
}

.section-title {
  display: flex;
  align-items: baseline;
  gap: 10px;
  margin: 0 0 14px;
  padding-bottom: 9px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.07);
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: #6ee7ff;
}

.section-hint {
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.08em;
  text-transform: none;
  color: rgba(124, 132, 155, 0.95);
}

.empty {
  margin: 0;
  padding: 14px 16px;
  border: 1px dashed rgba(255, 255, 255, 0.1);
  border-radius: 10px;
  font-size: 12.5px;
  color: rgba(124, 132, 155, 0.95);
}

/* ── 资料 ─────────────────────────────────────────────────────────── */

.facts {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px 20px;
}

.fact,
.meta-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.fact-key {
  font-size: 10.5px;
  font-weight: 500;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: rgba(124, 132, 155, 0.95);
}

.fact-value {
  font-size: 13px;
  color: #e9ecf5;
  overflow-wrap: anywhere;
}

.mono {
  font-family: ui-monospace, 'SFMono-Regular', 'JetBrains Mono', Consolas, monospace;
  font-variant-numeric: tabular-nums;
  font-size: 12px;
  letter-spacing: 0.02em;
}

.bio {
  margin: 16px 0 0;
  padding: 12px 14px;
  border-left: 2px solid rgba(110, 231, 255, 0.5);
  border-radius: 0 8px 8px 0;
  background: rgba(110, 231, 255, 0.04);
  font-size: 12.5px;
  line-height: 1.75;
  color: #a9b1c6;
}

/* ── 授权 ─────────────────────────────────────────────────────────── */

.licenses {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.license {
  padding: 14px 16px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.02);
}

.license-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 12px;
}

.license-code {
  font-family: ui-monospace, 'SFMono-Regular', 'JetBrains Mono', Consolas, monospace;
  font-size: 12.5px;
  font-weight: 600;
  letter-spacing: 0.04em;
  color: #d6f4ff;
}

.license-meta {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 16px;
}

/* ── 权限 ─────────────────────────────────────────────────────────── */

.perm-blocks {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.perm-block-title {
  margin: 0 0 8px;
  font-size: 10.5px;
  font-weight: 600;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: rgba(124, 132, 155, 0.95);
}

.perm-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

/* ── 动态时间线 ───────────────────────────────────────────────────── */

.timeline {
  margin: 0;
  padding: 0 0 0 6px;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.tl-item {
  position: relative;
  display: flex;
  gap: 14px;
}

/* 竖线：除了最后一项，每项都往下连一段 */
.tl-item:not(:last-child)::before {
  content: '';
  position: absolute;
  left: 3px;
  top: 12px;
  bottom: -18px;
  width: 1px;
  background: rgba(255, 255, 255, 0.09);
}

.tl-dot {
  position: relative;
  z-index: 1;
  width: 7px;
  height: 7px;
  margin-top: 5px;
  flex: none;
  border-radius: 50%;
  box-shadow: 0 0 10px 1px currentColor;
}

.tl-body {
  min-width: 0;
}

.tl-head {
  display: flex;
  align-items: center;
  gap: 8px;
}

.tl-title {
  font-size: 13px;
  font-weight: 600;
  color: #e9ecf5;
}

.tl-detail {
  margin: 4px 0 0;
  font-size: 12px;
  color: #a9b1c6;
}

.tl-meta {
  margin: 4px 0 0;
  font-size: 11px;
  color: rgba(124, 132, 155, 0.9);
}

@media (max-width: 860px) {
  .facts,
  .licenses,
  .perm-blocks {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
