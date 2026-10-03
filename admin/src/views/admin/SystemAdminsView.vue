<script setup lang="ts">
import { computed, h, ref } from 'vue'
import {
  NButton,
  NPopconfirm,
  NSelect,
  NTag,
  useMessage,
  type DataTableColumns,
  type SelectOption,
} from 'naive-ui'
import UserIdentity from '@/components/admin/UserIdentity.vue'
import RoleTag from '@/components/admin/RoleTag.vue'
import PermissionPicker from '@/components/admin/PermissionPicker.vue'
import RolePermissionPanel from '@/components/admin/RolePermissionPanel.vue'
import PermissionManager from '@/components/admin/PermissionManager.vue'
import { mockUsers } from '@/mock/users'
import { findRole, roles } from '@/mock/roles'
import { TONE_COLORS, type PermissionKey, type UserRecord, type UserRole } from '@/types/user'

// 组件名必须和路由 name 一致 —— KeepAlive 的 include 按它匹配
defineOptions({ name: 'admin-system-admins' })

/**
 * 管理员设置 —— 权限体系的总入口。这里有三层，各自独立又互相引用：
 *
 *   权限点管理（按钮 → 抽屉）  最底层：系统里到底有哪些权限可用，可增删改
 *   身份权限  （按钮 → 抽屉）  中间层：每种身份默认拥有哪些权限（基线）
 *   管理员列表（本页表格）     最上层：谁在这个名单里、他一个人具体能做什么
 *
 * 为什么后两者做成按钮而不是各自占一个侧栏菜单项：
 * 它们是"配权限"这一件事的两个维度，散在侧栏里会让人以为是三件不相干的事。
 *
 * 增删改都在：
 *   新增 —— 添加管理员
 *   修改 —— 配置（身份 + 逐项权限）
 *   删除 —— 移除（降回普通用户并清空权限）
 *
 * 超级管理员（owner）隐含全部权限、不允许在这里改或移除 ——
 * 否则一个手滑就能把最高权限改没了，谁都进不去。
 */

const message = useMessage()

/** 管理团队：只列能进后台的管理员。版主/博主的权限走「身份权限」里的模板 */
const admins = computed(() =>
  mockUsers.filter((user) => user.role === 'owner' || user.role === 'admin'),
)

/** 可提升为管理员的候选人（当前还是普通用户的） */
const candidates = computed<SelectOption[]>(() =>
  mockUsers
    .filter((user) => user.role === 'member')
    .map((user) => ({ label: `${user.nickname} · ${user.publicId}`, value: user.id })),
)

/**
 * 可赋予的身份 —— 直接列全部角色（含界面上自定义的）。
 * 排除 owner：最高权限唯一，不该能被随手指派出去。
 */
const roleOptions = computed<SelectOption[]>(() =>
  roles
    .filter((role) => role.key !== 'owner')
    .map((role) => ({ label: role.name, value: role.key })),
)

// ── 抽屉开关 ──────────────────────────────────────────────────────────

const showRoles = ref(false)
const showPermissions = ref(false)

// ── 新增 ──────────────────────────────────────────────────────────────

const adding = ref(false)
const addTargetId = ref<number | null>(null)

function confirmAdd() {
  const target = mockUsers.find((user) => user.id === addTargetId.value)
  if (!target) return

  target.role = 'admin'
  target.permissions = [...(findRole('admin')?.permissions ?? [])]

  message.success(`已把「${target.nickname}」设为管理员`)
  adding.value = false
  addTargetId.value = null
  openEditor(target)
}

// ── 修改 ──────────────────────────────────────────────────────────────

const editing = ref<UserRecord | null>(null)
const draftRole = ref<UserRole>('admin')
const draftPermissions = ref<PermissionKey[]>([])

const showEditor = computed({
  get: () => editing.value !== null,
  set: (value: boolean) => {
    if (!value) closeEditor()
  },
})

function openEditor(user: UserRecord) {
  editing.value = user
  draftRole.value = user.role
  draftPermissions.value = [...user.permissions]
}

function closeEditor() {
  editing.value = null
  draftPermissions.value = []
}

/** 切换身份 → 套用该角色的默认权限基线 */
function onRoleChange(role: UserRole) {
  draftRole.value = role
  draftPermissions.value = [...(findRole(role)?.permissions ?? [])]
  message.info(`已套用「${findRole(role)?.name ?? role}」的默认权限，可继续逐项调整`)
}

function applyRoleTemplate() {
  draftPermissions.value = [...(findRole(draftRole.value)?.permissions ?? [])]
}

function save() {
  const target = editing.value
  if (!target) return

  // 假数据直接落在内存对象上；接后端时这里换成
  // PATCH /api/v1/admin/users/{id} { role, permissions }
  target.role = draftRole.value
  target.permissions = [...draftPermissions.value]

  message.success(
    `已更新「${target.nickname}」：${findRole(draftRole.value)?.name ?? draftRole.value} · ${draftPermissions.value.length} 项权限`,
  )
  closeEditor()
}

// ── 删除 ──────────────────────────────────────────────────────────────

function removeAdmin(user: UserRecord) {
  user.role = 'member'
  user.permissions = []
  // 接后端时：PATCH /api/v1/admin/users/{id} { role: 'member', permissions: [] }
  message.success(`已移除「${user.nickname}」的管理员身份，并清空其权限`)
}

const rowKey = (row: UserRecord) => row.id

const columns = computed<DataTableColumns<UserRecord>>(() => [
  {
    title: '管理员',
    key: 'user',
    minWidth: 240,
    render: (row) => h(UserIdentity, { user: row, size: 'sm' }),
  },
  {
    title: '身份',
    key: 'role',
    width: 130,
    render: (row) => h(RoleTag, { role: row.role, size: 'medium' }),
  },
  {
    title: '权限',
    key: 'permissions',
    width: 130,
    render: (row) => {
      if (row.role === 'owner') {
        return h(
          NTag,
          { size: 'small', bordered: false, color: TONE_COLORS.gold },
          { default: () => '全部权限' },
        )
      }
      return h(
        NTag,
        { size: 'small', bordered: false, color: TONE_COLORS.slate },
        { default: () => `${row.permissions.length} 项` },
      )
    },
  },
  { title: '最后活跃', key: 'lastSeenAt', width: 150 },
  {
    title: '操作',
    key: 'actions',
    width: 130,
    align: 'right',
    render: (row) => {
      if (row.role === 'owner') {
        return h('span', { style: 'color:#7c849b;font-size:12px' }, '不可修改')
      }

      return h('div', { class: 'row-actions' }, [
        h(
          NButton,
          { size: 'tiny', quaternary: true, type: 'primary', onClick: () => openEditor(row) },
          { default: () => '配置' },
        ),
        h(
          NPopconfirm,
          { onPositiveClick: () => removeAdmin(row) },
          {
            trigger: () =>
              h(
                NButton,
                { size: 'tiny', quaternary: true, type: 'error' },
                { default: () => '移除' },
              ),
            default: () =>
              `移除后「${row.nickname}」会变回普通用户，并失去全部后台权限。`,
          },
        ),
      ])
    },
  },
])
</script>

<template>
  <section class="module">
    <header class="mod-head">
      <div>
        <h1 class="mod-title">管理员设置</h1>
        <p class="mod-sub">
          指定谁可以进后台，以及他能做什么。身份决定默认权限，权限点决定实际能力 ——
          同为管理员，可以一个人管内容、一个人只管授权。
        </p>
      </div>

      <div class="head-actions">
        <n-button quaternary @click="showRoles = true">身份权限</n-button>
        <n-button quaternary @click="showPermissions = true">权限点管理</n-button>
        <n-button type="primary" ghost @click="adding = true">添加管理员</n-button>
      </div>
    </header>

    <n-data-table
      class="table"
      :columns="columns"
      :data="admins"
      :bordered="false"
      :single-line="false"
      :row-key="rowKey"
      size="small"
    />

    <!-- ── 添加管理员 ──────────────────────────────────────────────── -->
    <n-modal
      v-model:show="adding"
      preset="card"
      title="添加管理员"
      :style="{ width: 'min(480px, 92vw)' }"
    >
      <p class="modal-hint">从普通用户里挑一个人，设为管理员。之后可以继续给他调权限。</p>
      <n-select
        v-model:value="addTargetId"
        :options="candidates"
        filterable
        placeholder="搜索昵称或公开 ID"
      />
      <template #footer>
        <div class="foot-actions">
          <n-button quaternary @click="adding = false">取消</n-button>
          <n-button type="primary" :disabled="addTargetId === null" @click="confirmAdd">
            设为管理员
          </n-button>
        </div>
      </template>
    </n-modal>

    <!-- ── 配置某人（修改） ────────────────────────────────────────── -->
    <n-drawer v-model:show="showEditor" placement="right" width="min(820px, 94vw)" :auto-focus="false">
      <n-drawer-content v-if="editing" title="配置管理员" :native-scrollbar="false" closable>
        <UserIdentity :user="editing" size="md" class="subject" />

        <section class="block">
          <h4 class="block-title">身份</h4>
          <n-select :value="draftRole" :options="roleOptions" @update:value="onRoleChange" />
          <p class="block-hint">
            切换身份会套用该身份的默认权限（在「身份权限」里配置），之后还能逐项微调。
          </p>
        </section>

        <section class="block">
          <h4 class="block-title">权限</h4>
          <PermissionPicker v-model:value="draftPermissions" />
        </section>

        <template #footer>
          <div class="foot">
            <n-button quaternary size="small" @click="applyRoleTemplate">套用身份默认权限</n-button>
            <div class="foot-actions">
              <n-button quaternary @click="closeEditor">取消</n-button>
              <n-button type="primary" @click="save">保存</n-button>
            </div>
          </div>
        </template>
      </n-drawer-content>
    </n-drawer>

    <!-- ── 身份权限（基线） ────────────────────────────────────────── -->
    <n-drawer v-model:show="showRoles" placement="right" width="min(1020px, 96vw)" :auto-focus="false">
      <n-drawer-content title="身份权限" :native-scrollbar="false" closable>
        <p class="drawer-hint">
          这里定的是<strong>基线</strong>：某种身份默认拥有哪些权限。具体某个人可以在管理员列表里
          单独加减，不会影响其它同身份的人。
        </p>
        <RolePermissionPanel />
      </n-drawer-content>
    </n-drawer>

    <!-- ── 权限点管理（增删改） ────────────────────────────────────── -->
    <n-drawer
      v-model:show="showPermissions"
      placement="right"
      width="min(900px, 96vw)"
      :auto-focus="false"
    >
      <n-drawer-content title="权限点管理" :native-scrollbar="false" closable>
        <p class="drawer-hint">
          系统里可用的全部权限点。新增、改名、删除都在这儿 ——
          删除时会同时清理所有引用，不会留下"幽灵权限"。
        </p>
        <PermissionManager />
      </n-drawer-content>
    </n-drawer>
  </section>
</template>

<style scoped>
.module {
  max-width: 1180px;
}

.mod-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  margin-bottom: 20px;
}

.head-actions {
  display: flex;
  gap: 8px;
  flex: none;
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
  max-width: 700px;
  font-size: 12.5px;
  line-height: 1.8;
  color: #7c849b;
}

.table {
  border: 1px solid rgba(255, 255, 255, 0.06);
  border-radius: 14px;
  overflow: hidden;
}

/* 一个单元格里并排两个按钮（h() 渲染出来的，得用 :deep） */
.table :deep(.row-actions) {
  display: flex;
  justify-content: flex-end;
  gap: 2px;
}

.modal-hint {
  margin: 0 0 14px;
  font-size: 12.5px;
  line-height: 1.7;
  color: #a9b1c6;
}

.foot-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.drawer-hint {
  margin: 0 0 18px;
  padding: 12px 14px;
  border-left: 2px solid rgba(110, 231, 255, 0.5);
  border-radius: 0 8px 8px 0;
  background: rgba(110, 231, 255, 0.04);
  font-size: 12.5px;
  line-height: 1.75;
  color: #a9b1c6;
}

/* ── 配置抽屉 ─────────────────────────────────────────────────────── */

.subject {
  padding-bottom: 16px;
  margin-bottom: 20px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.07);
}

.block {
  margin-bottom: 24px;
}

.block-title {
  margin: 0 0 10px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: #6ee7ff;
}

.block-hint {
  margin: 8px 0 0;
  font-size: 11.5px;
  line-height: 1.7;
  color: rgba(124, 132, 155, 0.95);
}

.foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

@media (max-width: 900px) {
  .mod-head {
    flex-direction: column;
  }

  .head-actions {
    flex-wrap: wrap;
  }
}
</style>
