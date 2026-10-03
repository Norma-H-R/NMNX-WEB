<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { NButton, NPopconfirm, NSelect, useMessage, type SelectOption } from 'naive-ui'
import PermissionPicker from './PermissionPicker.vue'
import {
  createRole,
  deleteRole,
  findRole,
  loadRoles,
  roles,
  ROLE_TONES,
  updateRole,
} from '@/api/roles'
import { allPermissionKeys } from '@/api/rbac'
import { ApiError } from '@/api/client'
import { TONE_COLORS, TONE_LABELS, type PermissionKey, type ToneName } from '@/types/user'

/**
 * 角色管理：新建 / 改名改配色 / 配默认权限 / 删除。
 *
 * 为什么角色必须能自定义：
 *   现实里"管理员"从来不是一个角色。论坛要两个人管，一个只能置顶加精、
 *   另一个还要能删帖封人 —— 硬套同一个角色就得给其中一个人单独调权限，
 *   那个人一走又得重调一遍。正确做法是拆成两个角色（"论坛运营""论坛主管"），
 *   按角色赋人，基线永远是准的。
 *
 * 布局是"左列表 + 右编辑器"：角色是会长到十几个的（每个业务板块一套），
 * 用标签页横排迟早放不下。
 *
 * 切换角色时如果有未保存改动会拦住 —— 权限这种东西点没了很难发现，
 * 宁可多一步确认。
 */
const message = useMessage()

const selectedKey = ref<string>('admin')
/** 右侧编辑器是否处于"新建"模式 */
const isCreating = ref(false)

const draft = reactive({
  name: '',
  desc: '',
  tone: 'cyan' as ToneName,
  permissions: [] as PermissionKey[],
})

const toneOptions: SelectOption[] = ROLE_TONES.map((tone) => ({
  label: TONE_LABELS[tone],
  value: tone,
}))

const currentRole = computed(() => (isCreating.value ? null : findRole(selectedKey.value)))

/** owner 隐含全部权限：只读展示"全勾"，不存快照（存了权限点一增删就过期） */
const isOwnerSelected = computed(() => !isCreating.value && selectedKey.value === 'owner')

const canRemove = computed(
  () => !isCreating.value && selectedKey.value !== 'owner' && selectedKey.value !== 'member',
)

function loadRole(key: string) {
  const role = findRole(key)
  if (!role) return
  isCreating.value = false
  selectedKey.value = key
  draft.name = role.name
  draft.desc = role.desc
  draft.tone = role.tone
  draft.permissions = [...role.permissions]
}

// 角色清单在 core（PHP）那边，是**异步**来的 ——
// 不能在 setup 阶段直接 loadRole（那时 roles 还是空的）。
// 拉回来之后再落到默认选中的那个角色。
onMounted(async () => {
  try {
    await loadRoles()
  } catch (error) {
    message.error(error instanceof ApiError ? error.message : '角色清单加载失败')
    return
  }

  loadRole('admin')
})

function selectRole(key: string) {
  if (key === selectedKey.value && !isCreating.value) return

  if (dirty.value) {
    message.warning('当前角色有未保存的改动，请先保存或点「还原」')
    return
  }
  loadRole(key)
}

function startCreate() {
  if (dirty.value) {
    message.warning('当前角色有未保存的改动，请先保存或点「还原」')
    return
  }
  isCreating.value = true
  selectedKey.value = ''
  draft.name = ''
  draft.desc = ''
  draft.tone = 'cyan'
  draft.permissions = []
}

function resetDraft() {
  if (isCreating.value) {
    loadRole(roles[0]?.key ?? 'member')
    return
  }
  loadRole(selectedKey.value)
}

const dirty = computed(() => {
  if (isCreating.value) return draft.name.trim().length > 0

  const role = currentRole.value
  if (!role) return false

  if (draft.name !== role.name || draft.desc !== role.desc || draft.tone !== role.tone) return true

  const list = draft.permissions
  const saved = role.permissions
  return list.length !== saved.length || list.some((key) => !saved.includes(key))
})

async function handleSave() {
  const name = draft.name.trim()
  if (!name) {
    message.warning('请填写角色名称')
    return
  }

  try {
    if (isCreating.value) {
      const created = await createRole({
        name,
        desc: draft.desc.trim(),
        tone: draft.tone,
        permissions: draft.permissions,
      })
      loadRole(created.key)
      message.success(`已创建角色「${created.name}」，现在可以把它赋予用户了`)
      return
    }

    await updateRole(selectedKey.value, {
      name,
      desc: draft.desc,
      tone: draft.tone,
      permissions: draft.permissions,
    })

    loadRole(selectedKey.value)
    message.success(`已保存「${name}」，该身份下的用户权限基线随之更新`)
  } catch (error) {
    // 后端给的 message 已经是人话（权限点不存在 / 超级管理员不能配基线 …）
    message.error(error instanceof ApiError ? error.message : '保存失败')
  }
}

async function handleRemove() {
  const key = selectedKey.value

  try {
    // 挂在这个角色下的用户由**后端**先转成 member 再删 ——
    // 所以这里不需要（也没有）原来 mock 那一步 migrateRoleUsers
    const moved = await deleteRole(key)

    message.success(moved ? `已删除该角色，${moved} 个用户已转为普通用户` : '已删除该角色')
  } catch (error) {
    message.error(error instanceof ApiError ? error.message : '删除失败')
    return
  }

  // 删完直接落到列表第一个（不走 selectRole 的 dirty 拦截，draft 已经作废）
  loadRole(roles[0]?.key ?? 'member')
}

/**
 * 喂给 PermissionPicker 的值。
 * owner 是只读的"全勾"展示；给个空 setter 是为了不让 v-model 往只读 computed 上写
 * （虽然 picker 在 readonly 时不会 emit，但留个 setter 更稳，也省掉一条开发期警告）。
 */
const pickerValue = computed<PermissionKey[]>({
  // 展开成新数组再给出去：那是响应式数组本身，直接交出去等于让人改到源头
  get: () => (isOwnerSelected.value ? [...allPermissionKeys] : draft.permissions),
  set: (keys) => {
    if (!isOwnerSelected.value) draft.permissions = [...keys]
  },
})
</script>

<template>
  <div class="roles">
    <!-- ── 角色列表 ────────────────────────────────────────────────── -->
    <aside class="list">
      <button
        v-for="role in roles"
        :key="role.key"
        class="item"
        :class="{ 'is-active': !isCreating && role.key === selectedKey }"
        type="button"
        @click="selectRole(role.key)"
      >
        <span class="dot" :style="{ background: TONE_COLORS[role.tone].textColor }" />
        <span class="item-main">
          <span class="item-name">{{ role.name }}</span>
          <span class="item-meta">
            {{ role.users_count ?? 0 }} 人 · {{ role.permissions.length }} 项权限
          </span>
        </span>
      </button>

      <button
        class="item is-create"
        :class="{ 'is-active': isCreating }"
        type="button"
        @click="startCreate"
      >
        <span class="plus">+</span>
        <span class="item-main">
          <span class="item-name">新建角色</span>
        </span>
      </button>
    </aside>

    <!-- ── 编辑器 ──────────────────────────────────────────────────── -->
    <section class="editor">
      <div class="fields">
        <label class="field">
          <span class="field-label">角色名称</span>
          <n-input v-model:value="draft.name" placeholder="例如：论坛管理员 A" />
        </label>

        <label class="field">
          <span class="field-label">徽章配色</span>
          <n-select v-model:value="draft.tone" :options="toneOptions" />
        </label>

        <label class="field is-wide">
          <span class="field-label">说明</span>
          <n-input v-model:value="draft.desc" placeholder="这个身份能做什么（只有后台看得到）" />
        </label>
      </div>

      <PermissionPicker v-model:value="pickerValue" :readonly="isOwnerSelected" />

      <div class="savebar">
        <span class="dirty" :class="{ 'is-on': dirty }">
          {{
            isCreating
              ? '新建角色：填好名称与权限后保存'
              : dirty
                ? '有未保存的改动'
                : '已与保存内容一致'
          }}
        </span>

        <div class="actions">
          <n-button quaternary size="small" @click="resetDraft">
            {{ isCreating ? '取消' : '还原' }}
          </n-button>

          <n-popconfirm v-if="canRemove" @positive-click="handleRemove">
            <template #trigger>
              <n-button quaternary size="small" type="error">删除角色</n-button>
            </template>
            <div class="confirm">
              <p class="confirm-title">删除「{{ currentRole?.name }}」？</p>
              <p class="confirm-body">
                当前有
                <strong>{{ currentRole?.users_count ?? 0 }}</strong> 个用户挂着这个身份，
                删除后他们会转为普通用户并失去对应权限。
              </p>
            </div>
          </n-popconfirm>

          <n-button type="primary" :disabled="!dirty" @click="handleSave">
            {{ isCreating ? '创建角色' : '保存' }}
          </n-button>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.roles {
  display: grid;
  grid-template-columns: 232px minmax(0, 1fr);
  gap: 20px;
  align-items: start;
}

/* ── 左列表 ───────────────────────────────────────────────────────── */

.list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 8px;
  border: 1px solid rgba(255, 255, 255, 0.07);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.015);
}

.item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border: 0;
  border-radius: 9px;
  background: none;
  color: #a9b1c6;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition:
    background-color 0.22s ease,
    color 0.22s ease;
}

.item:hover {
  background: rgba(255, 255, 255, 0.045);
  color: #e9ecf5;
}

.item.is-active {
  background: rgba(110, 231, 255, 0.12);
  color: #ffffff;
}

.dot {
  width: 8px;
  height: 8px;
  flex: none;
  border-radius: 50%;
  box-shadow: 0 0 8px 0 currentColor;
}

.item-main {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.item-name {
  font-size: 12.5px;
  font-weight: 600;
}

.item-meta {
  font-size: 10.5px;
  color: rgba(124, 132, 155, 0.95);
}

.item.is-create {
  margin-top: 4px;
  border-top: 1px solid rgba(255, 255, 255, 0.06);
  border-radius: 0 0 9px 9px;
  padding-top: 12px;
  color: #6ee7ff;
}

.plus {
  width: 8px;
  flex: none;
  font-size: 14px;
  line-height: 1;
  text-align: center;
}

/* ── 右编辑器 ─────────────────────────────────────────────────────── */

.editor {
  min-width: 0;
}

.fields {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 170px;
  gap: 14px;
  margin-bottom: 20px;
}

.field.is-wide {
  grid-column: 1 / -1;
}

.field-label {
  display: block;
  margin-bottom: 7px;
  font-size: 10.5px;
  font-weight: 500;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: #7c849b;
}

/* 保存条吸底：配了近百个权限之后，不用一路滚回顶去找按钮 */
.savebar {
  position: sticky;
  bottom: 0;
  z-index: 3;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-top: 20px;
  padding: 12px 16px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 12px;
  background: rgba(10, 13, 26, 0.88);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.dirty {
  font-size: 12px;
  color: rgba(124, 132, 155, 0.95);
}

.dirty.is-on {
  color: #f2d18d;
}

.actions {
  display: flex;
  gap: 8px;
}

/* ── 删除确认 ─────────────────────────────────────────────────────── */

.confirm {
  max-width: 260px;
}

.confirm-title {
  margin: 0 0 6px;
  font-size: 13px;
  font-weight: 600;
}

.confirm-body {
  margin: 0;
  font-size: 12px;
  line-height: 1.7;
  color: #a9b1c6;
}

.confirm-body strong {
  color: #f2d18d;
}

@media (max-width: 900px) {
  .roles {
    grid-template-columns: minmax(0, 1fr);
  }

  .fields {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
