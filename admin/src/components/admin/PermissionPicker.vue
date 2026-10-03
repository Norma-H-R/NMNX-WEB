<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { allPermissionKeys, loadPermissions, permissionGroups } from '@/api/rbac'
import type { PermissionGroup, PermissionKey } from '@/types/user'

/**
 * 权限勾选面板。
 *
 * 「配置管理员」和「身份权限」用的是同一个面板 —— 这两处交互本来就一模一样，
 * 写两份迟早会长歪。
 *
 * 完全受控：value 进、update:value 出，自己不留状态。
 * 这样父组件可以随时把它重置成"角色的默认权限"，面板不用管这些逻辑。
 *
 * 权限清单**来自 core（PHP）后端**（`GET /api/v1/admin/permissions`），
 * 所以这里读的是响应式数据而不是 import 进来的常量 ——
 * 「权限点管理」里新增一项，这个面板会立刻多出一行。
 */

// 面板可能比「权限点管理」先被挂载（比如直接进「身份权限」页），
// 所以自己也拉一次（幂等，重复拉不会出问题）
onMounted(() => {
  loadPermissions().catch(() => {
    // 失败时保持空面板即可：真正的报错提示由页面自己给，面板不该抢这个活
  })
})
const props = withDefaults(
  defineProps<{
    value: PermissionKey[]
    /** 只读：超级管理员的权限恒为全部，不该被改 */
    readonly?: boolean
  }>(),
  { readonly: false },
)

const emit = defineEmits<{ 'update:value': [keys: PermissionKey[]] }>()

// 用 Set 而不是每次 includes()：
// 权限点近百个、已选的可能几十个，逐个 includes 就是 O(n×m)，每次重渲染都要跑一遍
const allKeys = computed(() => new Set(allPermissionKeys))
const total = computed(() => allKeys.value.size)
const selectedCount = computed(() => props.value.filter((key) => allKeys.value.has(key)).length)
const allSelected = computed(() => selectedCount.value >= total.value)

function toggleItem(key: PermissionKey, checked: boolean) {
  if (props.readonly) return
  const next = checked ? [...props.value, key] : props.value.filter((item) => item !== key)
  emit('update:value', [...new Set(next)])
}

function groupKeys(group: PermissionGroup): PermissionKey[] {
  return group.items.map((item) => item.key)
}

function isGroupAll(group: PermissionGroup): boolean {
  return groupKeys(group).every((key) => props.value.includes(key))
}

function toggleGroup(group: PermissionGroup, checked: boolean) {
  if (props.readonly) return
  const keys = groupKeys(group)
  const next = checked
    ? [...props.value, ...keys]
    : props.value.filter((key) => !keys.includes(key))
  emit('update:value', [...new Set(next)])
}

function selectAll() {
  if (props.readonly) return
  // 展开成新数组再往上抛：直接把响应式数组交出去，父组件拿到的会是"活"的引用
  emit('update:value', [...allPermissionKeys])
}

function clearAll() {
  if (props.readonly) return
  emit('update:value', [])
}
</script>

<template>
  <div class="picker">
    <div class="picker-bar">
      <span class="picker-count">
        已选 <strong>{{ selectedCount }}</strong> / {{ total }} 项
      </span>

      <div v-if="!readonly" class="picker-actions">
        <n-button size="tiny" quaternary :disabled="allSelected" @click="selectAll">
          全部勾选
        </n-button>
        <n-button size="tiny" quaternary :disabled="selectedCount === 0" @click="clearAll">
          清空
        </n-button>
      </div>

      <span v-else class="picker-readonly">超级管理员隐含全部权限，不可修改</span>
    </div>

    <div class="groups">
      <section v-for="group in permissionGroups" :key="group.title" class="group">
        <header class="group-head">
          <h4 class="group-title">{{ group.title }}</h4>
          <n-checkbox
            v-if="!readonly"
            size="small"
            :checked="isGroupAll(group)"
            @update:checked="(checked: boolean) => toggleGroup(group, checked)"
          >
            全选
          </n-checkbox>
        </header>

        <div class="items">
          <n-checkbox
            v-for="item in group.items"
            :key="item.key"
            class="item"
            :checked="value.includes(item.key)"
            :disabled="readonly"
            @update:checked="(checked: boolean) => toggleItem(item.key, checked)"
          >
            <span class="item-label">
              {{ item.label }}
              <span v-if="item.custom" class="item-custom">自定义</span>
            </span>
            <span class="item-desc">{{ item.desc }}</span>
          </n-checkbox>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.picker {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* ── 顶部计数条 ───────────────────────────────────────────────────── */

.picker-bar {
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

.picker-count {
  font-size: 12px;
  color: #a9b1c6;
}

.picker-count strong {
  margin: 0 2px;
  color: #6ee7ff;
  font-size: 14px;
}

.picker-actions {
  display: flex;
  gap: 4px;
}

.picker-readonly {
  font-size: 11.5px;
  color: rgba(124, 132, 155, 0.95);
}

/* ── 分组 ─────────────────────────────────────────────────────────── */

/* 流式：按**列**排，谁矮谁就被下一张补上空隙。
   ⚠️ 不能用 grid（哪怕加 align-items: start）：grid 每行的行高取该行最高的那张卡，
      矮的下面永远是空的 —— 就是"博客只有 10 条、右边空一大片"那种效果。
      多列是"竖着填满一列再开下一列"，空隙天然被吃掉。 */
.groups {
  column-width: 340px;
  column-gap: 14px;
}

.group {
  padding: 14px 16px;
  border: 1px solid rgba(255, 255, 255, 0.07);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.015);

  /* 多列布局必须做的两件事：
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
  margin-bottom: 10px;
  padding-bottom: 8px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

.group-title {
  margin: 0;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: #6ee7ff;
}

.items {
  display: flex;
  flex-direction: column;
  gap: 9px;
}

/* 每项两行：动作名 + 一行解释，勾什么一眼看得懂 */
.item {
  display: block;
}

.item :deep(.n-checkbox__label) {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.item :deep(.n-checkbox-box-wrapper) {
  margin-top: 1px;
}

.item-label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 600;
  color: #e9ecf5;
}

/* 界面新增的权限点打个标，和内置的区分开 */
.item-custom {
  padding: 1px 5px;
  border: 1px solid rgba(167, 139, 250, 0.4);
  border-radius: 4px;
  font-size: 9px;
  font-weight: 600;
  letter-spacing: 0.1em;
  color: #bda6ff;
}

.item-desc {
  font-size: 11px;
  line-height: 1.5;
  color: rgba(124, 132, 155, 0.95);
}

/* 窄屏：多列布局靠 `column-width` 自动减列。
   这里只把"一列的最小宽度"降下来 —— 屏幕小于两列宽度时，浏览器自己就并成一列了。
   ⚠️ 别再写回 `grid-template-columns` —— `.groups` 已经不是 grid 了，那条会静默失效。 */
@media (max-width: 900px) {
  .groups {
    column-width: 260px;
  }
}
</style>
