<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { NButton, NPopconfirm, NSelect, useMessage, type SelectOption } from 'naive-ui'
import {
  addPermission,
  groupTitles,
  loadPermissions,
  permissionGroups,
  removePermission,
  updatePermission,
} from '@/api/rbac'
import type { PermissionItem } from '@/types/user'

/**
 * 权限点管理：新增 / 编辑 / 删除。
 *
 * 数据**来自 core（PHP）后端**（`GET /api/v1/admin/permissions`），不再是本地假数据 ——
 * 所以这个页面看到的永远是数据库里的真实目录。
 *
 * 为什么必须能删改，而不只是"从固定清单里勾选"：
 *   团队会长出只有自己才懂的权限需求（"素材库""告警规则"这种），
 *   如果每个都要开发改代码发版，权限体系就没人愿意用了。
 *
 * 引用清理**不用前端操心**：后端外键是级联删除，角色基线与个人增减里的引用会一起清掉，
 * 不会留下指向不存在权限的"幽灵项"。
 * ⚠️ 也**没有**"改名同步引用"这回事 —— 后端存的是权限点的数字 ID，改 key 天然安全
 *    （旧 mock 里那套 renamePermissionRefs / renamePermissionInRoles 已经不需要了）。
 */
const message = useMessage()

/** 可选分组：由目录本身派生，避免和后端各存一份、早晚对不上 */
const groupOptions = computed<SelectOption[]>(() =>
  groupTitles.value.map((title) => ({ label: title, value: title })),
)

/** 界面上新增的权限点数量，用于顶部提示 */
const customCount = computed(
  () => permissionGroups.flatMap((group) => group.items).filter((item) => item.custom).length,
)

// ── 新增 / 编辑弹窗 ───────────────────────────────────────────────────

const showForm = ref(false)
/** null 表示"新增"，否则是正在编辑的权限点**后端主键**（不是 key —— key 允许改名） */
const editingId = ref<number | null>(null)

const form = reactive({ key: '', label: '', desc: '', groupTitle: '' })

const formTitle = computed(() => (editingId.value === null ? '新增权限点' : '编辑权限点'))

function openCreate() {
  editingId.value = null
  form.key = ''
  form.label = ''
  form.desc = ''
  form.groupTitle = groupTitles.value[0] ?? ''
  showForm.value = true
}

function openEdit(item: PermissionItem, groupTitle: string) {
  editingId.value = item.id
  form.key = item.key
  form.label = item.label
  form.desc = item.desc
  form.groupTitle = groupTitle
  showForm.value = true
}

/**
 * 标识只允许「模块.动作」这种小写点分格式。
 * 它最终会被后端当能力名用（中间件里写 `can('forum.post.delete')`），
 * 随手起个带空格或大写的名字，后面必然要改。
 *
 * 前端拦一道是为了**即时反馈**；后端 `RbacService` 里有同样的一条，两边都会拦。
 */
const KEY_PATTERN = /^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/

async function submit() {
  const key = form.key.trim()
  const label = form.label.trim()
  const desc = form.desc.trim()

  if (!label) {
    message.warning('请填写权限名称')
    return
  }
  if (!KEY_PATTERN.test(key)) {
    message.warning('标识要写成「模块.动作」的小写点分格式，比如 forum.post.delete')
    return
  }

  const creating = editingId.value === null

  // 交给后端：它会校验格式/重复/分组，并把结果（中文错误）原样带回来
  const error = creating
    ? await addPermission({ key, label, desc, groupTitle: form.groupTitle })
    : await updatePermission(editingId.value as number, { key, label, desc })

  if (error) {
    message.error(error)
    return
  }

  // 不需要"同步引用"：后端存的是权限点的数字 ID，改 key 天然安全
  message.success(creating ? `已新增权限点「${label}」` : `已更新「${label}」`)
  showForm.value = false
}

// ── 删除 ──────────────────────────────────────────────────────────────

/**
 * 删除权限点。
 *
 * 边界/注意：
 *   引用（角色基线、个人增减）由**后端外键级联**清理，前端不需要做任何收尾。
 *   所以这里的确认弹窗也不再报"影响 N 个用户" —— 那个数字现在只有后端算得出来。
 */
async function confirmRemove(item: PermissionItem) {
  const error = await removePermission(item.id)

  if (error) {
    message.error(error)
    return
  }

  message.success(`已删除「${item.label}」`)
}

// 进页面就把目录拉回来 —— 数据在 core（PHP）那边，本地没有
onMounted(() => {
  loadPermissions().catch((error: unknown) => {
    message.error(error instanceof Error ? error.message : '权限目录加载失败')
  })
})
</script>

<template>
  <div class="manager">
    <div class="bar">
      <span class="bar-count">
        共 {{ permissionGroups.reduce((sum, group) => sum + group.items.length, 0) }} 个权限点
        <template v-if="customCount">· 其中 {{ customCount }} 个自定义</template>
      </span>
      <n-button type="primary" ghost size="small" @click="openCreate">新增权限点</n-button>
    </div>

    <div class="groups">
      <section v-for="group in permissionGroups" :key="group.title" class="group">
        <header class="group-head">
          <h4 class="group-title">{{ group.title }}</h4>
          <span class="group-count">{{ group.items.length }}</span>
        </header>

        <ul class="list">
          <li v-for="item in group.items" :key="item.key" class="row">
            <div class="row-main">
              <div class="row-title">
                <span class="label">{{ item.label }}</span>
                <span class="key mono">{{ item.key }}</span>
                <span v-if="item.custom" class="custom">自定义</span>
              </div>
              <p class="row-desc">{{ item.desc || '（无说明）' }}</p>
            </div>

            <div class="row-actions">
              <n-button size="tiny" quaternary @click="openEdit(item, group.title)">编辑</n-button>

              <n-popconfirm @positive-click="confirmRemove(item)">
                <template #trigger>
                  <n-button size="tiny" quaternary type="error">删除</n-button>
                </template>
                <div class="confirm">
                  <p class="confirm-title">删除「{{ item.label }}」？</p>
                  <p class="confirm-body">
                    删除后，角色身份与个人配置里对它的引用会一并清掉（由后端级联完成），此操作不可撤销。
                  </p>
                </div>
              </n-popconfirm>
            </div>
          </li>
        </ul>
      </section>
    </div>

    <!-- ── 新增 / 编辑 ─────────────────────────────────────────────── -->
    <n-modal v-model:show="showForm" preset="card" :title="formTitle" :style="{ width: 'min(520px, 92vw)' }">
      <div class="form">
        <label class="field">
          <span class="field-label">所属分组</span>
          <n-select
            v-model:value="form.groupTitle"
            :options="groupOptions"
            :disabled="editingId !== null"
            placeholder="选择分组"
          />
          <span class="field-hint">编辑时不允许换分组，避免列表里位置突变让人找不到</span>
        </label>

        <label class="field">
          <span class="field-label">标识</span>
          <n-input v-model:value="form.key" placeholder="forum.post.delete" />
          <span class="field-hint">
            小写点分格式「模块.动作」。后端鉴权会直接用它，所以定了就别随意改 ——
            真改了系统会自动同步所有引用
          </span>
        </label>

        <label class="field">
          <span class="field-label">权限名称</span>
          <n-input v-model:value="form.label" placeholder="删除帖子" />
        </label>

        <label class="field">
          <span class="field-label">说明</span>
          <n-input
            v-model:value="form.desc"
            type="textarea"
            :rows="2"
            placeholder="删除任意主题"
          />
        </label>
      </div>

      <template #footer>
        <div class="foot-actions">
          <n-button quaternary @click="showForm = false">取消</n-button>
          <n-button type="primary" @click="submit">
            {{ editingId === null ? '创建' : '保存' }}
          </n-button>
        </div>
      </template>
    </n-modal>
  </div>
</template>

<style scoped>
.manager {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 14px;
  border: 1px solid rgba(110, 231, 255, 0.18);
  border-radius: 10px;
  background: rgba(110, 231, 255, 0.04);
  position: sticky;
  top: 0;
  z-index: 2;
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}

.bar-count {
  font-size: 12px;
  color: #a9b1c6;
}

/* ── 分组 ─────────────────────────────────────────────────────────── */

/* 流式布局：卡片按**列**排，谁矮谁就被下一张补上空隙。
   不用等宽、也不追求等高 —— 分组大小差得很远（3 个 vs 19 个，
   高度能差好几倍），等高只会每行都拖出一大片空白。

   为什么用 CSS 多列而不是 flex-wrap：
   flex 是一行一行铺的，行高取该行最高的那张，矮的那张下面永远是空的；
   多列是"竖着填满一列再开下一列"，天然把空隙吃掉 —— 这才是"由下一条补上"。 */
.groups {
  column-width: 340px;
  column-gap: 14px;
}

.group {
  border: 1px solid rgba(255, 255, 255, 0.07);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.015);
  overflow: hidden;

  /* 多列布局下必须做的两件事：
     1) break-inside: avoid —— 一张卡片不被从中间劈到下一列（劈开通读性很差）；
     2) 用 margin-bottom 当纵向间距 —— column-gap 只管列与列之间，不管上下。 */
  break-inside: avoid;
  margin-bottom: 14px;
}

.group-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 11px 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  background: rgba(255, 255, 255, 0.02);
}

.group-title {
  margin: 0;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: #6ee7ff;
}

.group-count {
  font-size: 11px;
  color: rgba(124, 132, 155, 0.9);
}

.list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 11px 16px;
  transition: background-color 0.2s ease;
}

.row + .row {
  border-top: 1px solid rgba(255, 255, 255, 0.04);
}

.row:hover {
  background: rgba(110, 231, 255, 0.04);
}

.row-main {
  min-width: 0;
}

.row-title {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.label {
  font-size: 12.5px;
  font-weight: 600;
  color: #e9ecf5;
}

.key {
  font-size: 11px;
  color: rgba(124, 132, 155, 0.95);
}

.mono {
  font-family: ui-monospace, 'SFMono-Regular', 'JetBrains Mono', Consolas, monospace;
  letter-spacing: 0.02em;
}

.custom {
  padding: 1px 5px;
  border: 1px solid rgba(167, 139, 250, 0.4);
  border-radius: 4px;
  font-size: 9px;
  font-weight: 600;
  letter-spacing: 0.1em;
  color: #bda6ff;
}

.row-desc {
  margin: 2px 0 0;
  font-size: 11.5px;
  color: rgba(124, 132, 155, 0.9);
}

/* 操作按钮平时半透明，悬停整行才完全显形 —— 一屏近百个"编辑/删除"太吵 */
.row-actions {
  display: flex;
  gap: 2px;
  flex: none;
  opacity: 0.45;
  transition: opacity 0.2s ease;
}

.row:hover .row-actions {
  opacity: 1;
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

/* ── 表单 ─────────────────────────────────────────────────────────── */

.form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.field {
  display: block;
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

.field-hint {
  display: block;
  margin-top: 6px;
  font-size: 11px;
  line-height: 1.6;
  color: rgba(124, 132, 155, 0.9);
}

.foot-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
</style>
