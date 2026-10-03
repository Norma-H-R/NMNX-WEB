<script setup lang="ts">
import { computed, h, ref } from 'vue'
import { NButton, type DataTableColumns, type SelectOption } from 'naive-ui'
import UserIdentity from '@/components/admin/UserIdentity.vue'
import RoleTag from '@/components/admin/RoleTag.vue'
import StatusTag from '@/components/admin/StatusTag.vue'
import NavIcon from '@/components/admin/NavIcon.vue'
import UserDetailDrawer from '@/components/admin/UserDetailDrawer.vue'
import { listUsers } from '@/mock/users'
import { roles } from '@/mock/roles'
import { STATUS_META, type UserRecord, type UserRole, type UserStatus } from '@/types/user'

// 组件名必须和路由 name 一致 —— KeepAlive 的 include 按它匹配
defineOptions({ name: 'admin-user' })

/**
 * 用户模块。
 *
 * 数据来自 src/mock/users.ts（core 的 user 表还没建）。接接口时只要把
 * listUsers() 换成真实请求，本页的渲染逻辑一行都不用改。
 *
 * 展示风格的统一交给三个组件：UserIdentity（头像 + 昵称 + 公开 ID + 身份徽章）
 * 和 RoleTag / StatusTag。**不要在页面里手写用户长什么样** ——
 * 那是"统一风格"这件事唯一会失控的地方。
 */

const users = ref<UserRecord[]>(listUsers())

const keyword = ref('')
const roleFilter = ref<UserRole | null>(null)
const statusFilter = ref<UserStatus | null>(null)

/** 身份筛选项直接列全部角色（含自定义的）—— 新建角色后这里会自动多一项 */
const roleOptions = computed<SelectOption[]>(() =>
  roles.map((role) => ({ label: role.name, value: role.key })),
)

const statusOptions: SelectOption[] = (Object.keys(STATUS_META) as UserStatus[]).map((status) => ({
  label: STATUS_META[status].label,
  value: status,
}))

/** 搜索同时匹配昵称、公开 ID 和邮箱 —— 用户往往只记得其中某一个 */
const filtered = computed(() => {
  const kw = keyword.value.trim().toLowerCase()
  return users.value.filter((user) => {
    if (roleFilter.value && user.role !== roleFilter.value) return false
    if (statusFilter.value && user.status !== statusFilter.value) return false
    if (!kw) return true
    return (
      user.nickname.toLowerCase().includes(kw) ||
      user.publicId.toLowerCase().includes(kw) ||
      user.email.toLowerCase().includes(kw)
    )
  })
})

const stats = computed(() => {
  const all = users.value
  return [
    { label: '总用户', value: all.length, hint: '全部注册账号' },
    {
      label: '管理团队',
      value: all.filter((user) => user.role === 'owner' || user.role === 'admin').length,
      hint: '超级管理员 + 管理员',
    },
    {
      label: '论坛版主',
      value: all.filter((user) => user.role === 'moderator').length,
      hint: '可管理帖子与评论',
    },
    {
      label: '博客博主',
      value: all.filter((user) => user.role === 'blogger').length,
      hint: '可发布博客内容',
    },
  ]
})

const hasFilter = computed(
  () => Boolean(keyword.value.trim()) || roleFilter.value !== null || statusFilter.value !== null,
)

function resetFilters() {
  keyword.value = ''
  roleFilter.value = null
  statusFilter.value = null
}

// 写在 script 里而不是模板内联：模板里的类型标注支持有限，row-key 又是表格的刚需
const rowKey = (row: UserRecord) => row.id

/**
 * 详情用**右侧宽抽屉**而不是弹窗：
 * 一个用户要看的东西太多（资料、名下授权、绑定机器、权限、操作轨迹），
 * 弹窗要么内部再套滚动条、要么高度撑爆，抽屉既能给足空间又不离开列表。
 */
const showDetail = ref(false)
const detailUserId = ref<number | null>(null)

function openDetail(user: UserRecord) {
  detailUserId.value = user.id
  showDetail.value = true
}

const columns = computed<DataTableColumns<UserRecord>>(() => [
  {
    title: '用户',
    key: 'user',
    minWidth: 230,
    render: (row) => h(UserIdentity, { user: row, size: 'sm' }),
  },
  {
    title: '身份',
    key: 'role',
    width: 118,
    render: (row) => h(RoleTag, { role: row.role }),
  },
  {
    title: '状态',
    key: 'status',
    width: 94,
    render: (row) => h(StatusTag, { status: row.status }),
  },
  { title: '邮箱', key: 'email', minWidth: 190 },
  { title: '注册时间', key: 'registeredAt', width: 148 },
  { title: '最后活跃', key: 'lastSeenAt', width: 148 },
  {
    title: '操作',
    key: 'actions',
    width: 82,
    align: 'right',
    render: (row) =>
      h(
        NButton,
        { size: 'tiny', quaternary: true, type: 'primary', onClick: () => openDetail(row) },
        { default: () => '详情' },
      ),
  },
])
</script>

<template>
  <section class="module">
    <header class="mod-head">
      <h1 class="mod-title">用户</h1>
      <p class="mod-sub">全部注册用户，以及他们的身份与账号状态</p>
    </header>

    <div class="stats">
      <div v-for="item in stats" :key="item.label" class="stat-card">
        <n-statistic :label="item.label" :value="item.value" />
        <p class="stat-hint">{{ item.hint }}</p>
      </div>
    </div>

    <div class="toolbar">
      <n-input v-model:value="keyword" clearable placeholder="搜索昵称 / 公开 ID / 邮箱" class="search">
        <template #prefix>
          <NavIcon name="search" />
        </template>
      </n-input>

      <n-select
        v-model:value="roleFilter"
        :options="roleOptions"
        clearable
        placeholder="全部身份"
        class="filter"
      />
      <n-select
        v-model:value="statusFilter"
        :options="statusOptions"
        clearable
        placeholder="全部状态"
        class="filter"
      />

      <n-button v-if="hasFilter" quaternary @click="resetFilters">重置</n-button>
    </div>

    <n-data-table
      class="table"
      :columns="columns"
      :data="filtered"
      :bordered="false"
      :single-line="false"
      :row-key="rowKey"
      :pagination="{ pageSize: 10 }"
      size="small"
    />

    <UserDetailDrawer v-model:show="showDetail" :user-id="detailUserId" />
  </section>
</template>

<style scoped>
.module {
  max-width: 1180px;
}

.mod-head {
  margin-bottom: 4px;
}

.mod-title {
  margin: 0;
  font-size: 22px;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: #f2f5ff;
}

.mod-sub {
  margin: 8px 0 0;
  font-size: 12.5px;
  color: #7c849b;
}

/* ── 指标卡 ───────────────────────────────────────────────────────── */

.stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin: 20px 0 18px;
}

.stat-card {
  padding: 16px 18px;
  border: 1px solid rgba(255, 255, 255, 0.07);
  border-radius: 14px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.012));
}

.stat-hint {
  margin: 6px 0 0;
  font-size: 11.5px;
  color: rgba(124, 132, 155, 0.9);
}

/* ── 工具栏 ───────────────────────────────────────────────────────── */

.toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
}

.search {
  max-width: 300px;
}

.filter {
  width: 142px;
}

/* ── 表格 ─────────────────────────────────────────────────────────── */

/* 给表格套一层圆角描边，让它像一张"卡片"浮在深空底上 */
.table {
  border: 1px solid rgba(255, 255, 255, 0.06);
  border-radius: 14px;
  overflow: hidden;
}

@media (max-width: 1080px) {
  .stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 720px) {
  .toolbar {
    flex-wrap: wrap;
  }

  .search {
    max-width: none;
    flex: 1;
    min-width: 200px;
  }
}
</style>
