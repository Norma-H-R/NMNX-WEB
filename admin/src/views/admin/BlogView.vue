<script setup lang="ts">
import { computed, h, onMounted, ref } from 'vue'
import {
  NButton,
  NInput,
  NPopconfirm,
  NSelect,
  NTag,
  useMessage,
  type DataTableColumns,
  type SelectOption,
} from 'naive-ui'
import { ApiError } from '@/api/client'
import { fetchBlogs, hideBlog, type BlogItem, type BlogStatus } from '@/api/blogs'
import { hasPermission } from '@/api/permissions'

// 组件名必须和路由 name 一致 —— KeepAlive 的 include 按它匹配
defineOptions({ name: 'admin-blog' })

/**
 * 博客管理（模块 10 R19）—— 跨会员的内容审核。
 *
 * 数据来自 core：`GET /api/v1/admin/blogs`，**不再有本地假数据**。
 * 后台只做两件事：**看**（全部状态，含草稿与已下架）和**下架 / 恢复**。
 *
 * 为什么后台没有"删除"：
 *   软删是作者自己的动作（会员端已有）。后台去删会和作者的认知打架 ——
 *   作者以为文章还在、只是没发出去，实际上是被官方删了。
 *   后台要"让内容消失"就用下架：可逆，而且作者那边能看到状态。
 *
 * 权限（后端定，前端只用它控显隐）：
 *   进这个页面要 `blog.read`；下架按钮还要 `blog.publish`（**只有 owner 有**）。
 *   没有它就**不显示按钮** —— 但注意 `hasPermission` 只是体验判断，
 *   真正拦截在后端的 `permission:` 中间件上。
 */

const message = useMessage()

// ── 列表状态 ──────────────────────────────────────────────────────────

const loading = ref(false)
const items = ref<BlogItem[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const status = ref<BlogStatus | ''>('')
const keyword = ref('')

/** 有没有"下架/恢复"的权限。没有就连按钮都不显示，免得点了白弹 403 */
const canPublish = computed(() => hasPermission('blog.publish'))

const statusOptions: SelectOption[] = [
  { label: '全部状态', value: '' },
  { label: '已发布', value: 'published' },
  { label: '草稿', value: 'draft' },
  { label: '已下架', value: 'hidden' },
]

/** 状态的显示方式（标签文字 + 颜色 + 说明） */
const STATUS_META: Record<BlogStatus, { label: string, tag: 'default' | 'success' | 'warning' }> = {
  draft: { label: '草稿', tag: 'default' },
  published: { label: '已发布', tag: 'success' },
  hidden: { label: '已下架', tag: 'warning' },
}

/**
 * 拉一页数据。
 *
 * 边界/注意：
 *   失败时**不动列表里的旧数据** —— 拉取失败还把表格清空的话，
 *   用户会以为"内容被删了"，而实际上只是后端没连上。
 */
async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchBlogs({
      status: status.value,
      keyword: keyword.value,
      page: page.value,
    })

    items.value = result.items
    total.value = result.total
    pageSize.value = result.perPage
  } catch (error) {
    // 后端给的 message 已经是人话（"连不上后端服务，请确认 core 已启动"）
    message.error(error instanceof ApiError ? error.message : '加载失败')
  } finally {
    loading.value = false
  }
}

/** 换筛选条件时回到第 1 页 —— 停在第 3 页却换了筛选，多半会看到空列表 */
function search(): void {
  page.value = 1
  void load()
}

function onPageChange(next: number): void {
  page.value = next
  void load()
}

/**
 * 下架 / 恢复。
 *
 * 边界/注意：
 *   成功后**只改这一行的状态**，不整表重拉：后端返回的就是更新后的那一篇
 *   （见 `hideBlog` 的返回），重拉一次既慢又会让当前页码/滚动位置跳掉。
 */
async function onToggleHide(blog: BlogItem): Promise<void> {
  const next = blog.status !== 'hidden'

  try {
    const updated = await hideBlog(blog.id, next)
    const index = items.value.findIndex((item) => item.id === blog.id)
    if (index >= 0) items.value[index] = updated

    message.success(next ? `已下架「${updated.title}」` : `已恢复「${updated.title}」`)
  } catch (error) {
    message.error(error instanceof ApiError ? error.message : '操作失败')
  }
}

/** 格式化时间：只到分钟，后台列表不需要秒 */
function formatTime(value: string | null): string {
  if (!value) return '—'

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'

  const pad = (n: number) => String(n).padStart(2, '0')

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}

// ── 表格列 ────────────────────────────────────────────────────────────

const columns = computed<DataTableColumns<BlogItem>>(() => [
  {
    // 封面 + 标题 + slug 合成一列：后台列表的每行都放一列封面会占掉太多横向空间
    key: 'title',
    title: '文章',
    minWidth: 280,
    render: (row) =>
      h('div', { class: 'cell-title' }, [
        row.cover
          ? h('img', { src: row.cover, class: 'cover', alt: '', loading: 'lazy' })
          : h('span', { class: 'cover is-empty', title: '没有封面（草稿允许）' }),
        h('div', { class: 'title-text' }, [
          h('div', { class: 'title' }, row.title),
          h('div', { class: 'slug' }, `/${row.slug}`),
        ]),
      ]),
  },
  {
    key: 'author',
    title: '作者',
    width: 150,
    render: (row) =>
      h('div', { class: 'cell-stack' }, [
        h('span', { class: 'strong' }, row.author?.name ?? '—'),
        h('span', { class: 'muted' }, row.published_at ? formatTime(row.published_at) : '未发布'),
      ]),
  },
  {
    key: 'tags',
    title: '标签',
    width: 170,
    render: (row) => {
      if (!row.tags.length) return h('span', { class: 'muted' }, '—')

      // 只显示前两个，剩下的收成 +N —— 后台是横向空间最紧的地方
      const shown = row.tags.slice(0, 2)
      const rest = row.tags.length - shown.length

      return h(
        'div',
        { class: 'tag-row' },
        [
          ...shown.map((tag) =>
            h(NTag, { size: 'small', bordered: false, key: tag }, { default: () => tag }),
          ),
          rest > 0 ? h('span', { class: 'muted' }, `+${rest}`) : null,
        ],
      )
    },
  },
  {
    key: 'stats',
    title: '数据',
    width: 160,
    render: (row) =>
      h('div', { class: 'cell-stats' }, [
        h('span', `赞 ${row.stats.like}`),
        h('span', `藏 ${row.stats.favorite}`),
        h('span', `评 ${row.stats.comment}`),
        h('span', `览 ${row.stats.view}`),
      ]),
  },
  {
    key: 'status',
    title: '状态',
    width: 90,
    render: (row) => {
      const meta = STATUS_META[row.status]

      return h(NTag, { size: 'small', bordered: false, type: meta.tag }, { default: () => meta.label })
    },
  },
  {
    key: 'actions',
    title: '操作',
    width: 96,
    // 后台表格的操作列固定右侧：横向滚动时按钮始终可见，不用来回滚
    fixed: 'right',
    render: (row) => {
      /*
       * 没权限时**根本不渲染这个按钮**。
       * 注意这只是不让用户白点 —— 真正的拦截在 core 的
       * `permission:blog.publish` 中间件上，伪造前端也提不了权。
       */
      if (!canPublish.value) {
        return h('span', { class: 'muted' }, '—')
      }

      const isHidden = row.status === 'hidden'

      return h(
        NPopconfirm,
        {
          onPositiveClick: () => onToggleHide(row),
          positiveText: isHidden ? '恢复' : '下架',
          negativeText: '取消',
        },
        {
          trigger: () =>
            h(
              NButton,
              { size: 'tiny', quaternary: true, type: isHidden ? 'primary' : 'warning' },
              { default: () => (isHidden ? '恢复' : '下架') },
            ),
          default: () =>
            isHidden
              ? '恢复后这篇会重新对外可见（若没有封面会被拒绝）。确定？'
              : '下架后对外与搜索引擎都不可见，作者那边能看到状态。确定？',
        },
      )
    },
  },
])

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="blog-view">
    <!--
      工具栏：一行放下筛选与刷新。
      后台是长时间停留的操作台，每个控件都单独占一行会把可视行数压到个位数。
    -->
    <div class="toolbar">
      <n-select
        v-model:value="status"
        :options="statusOptions"
        size="small"
        class="filter-status"
        @update:value="search"
      />

      <n-input
        v-model:value="keyword"
        size="small"
        class="filter-keyword"
        placeholder="搜标题或 slug，回车查询"
        clearable
        @keyup.enter="search"
        @clear="search"
      />

      <n-button size="small" quaternary @click="search">查询</n-button>

      <span class="count">共 {{ total }} 篇</span>
    </div>

    <n-data-table
      :columns="columns"
      :data="items"
      :loading="loading"
      :bordered="false"
      :single-line="false"
      :row-key="(row: BlogItem) => row.id"
      size="small"
      flex-height
      class="table"
    />

    <div class="pager">
      <n-pagination
        v-model:page="page"
        :page-size="pageSize"
        :item-count="total"
        size="small"
        :page-slot="6"
        @update:page="onPageChange"
      />
    </div>
  </div>
</template>

<style scoped>
/*
 * 这个页面刻意做得很紧：后台是长时间停留的操作台，
 * 一屏能多看到几行比"留白好看"重要得多。所以：
 *   工具栏一行、表格 size="small"、行内两层文字的间距压到 2px。
 */
.blog-view {
  display: flex;
  flex-direction: column;
  gap: 10px;
  height: 100%;
}

.toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
}

.filter-status {
  width: 128px;
}

.filter-keyword {
  width: 260px;
}

.count {
  margin-left: auto;
  font-size: 12px;
  color: #7c849b;
}

.table {
  flex: 1;
  min-height: 0;
}

.pager {
  display: flex;
  justify-content: flex-end;
}

/* ── 单元格内部（表格里不能用 scoped 的普通样式命中 render 出来的元素，故用 :deep） ── */

:deep(.cell-title) {
  display: flex;
  align-items: center;
  gap: 8px;
}

:deep(.cover) {
  flex: none;
  width: 44px;
  height: 30px;
  border-radius: 4px;
  object-fit: cover;
  background: rgba(255, 255, 255, 0.04);
}

/*
 * 没有封面时的占位块（规格里的决策 8）：
 * 渲染一个同尺寸的斜纹块，而不是留空洞 —— 否则这一行的网格会被撑歪。
 */
:deep(.cover.is-empty) {
  background-image: repeating-linear-gradient(
    135deg,
    rgba(255, 255, 255, 0.05) 0 4px,
    transparent 4px 8px
  );
}

:deep(.title-text) {
  min-width: 0;
}

:deep(.title) {
  font-size: 13px;
  color: #e9ecf5;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

:deep(.slug) {
  margin-top: 2px;
  font-size: 11px;
  color: #7c849b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

:deep(.cell-stack) {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

:deep(.strong) {
  font-size: 12.5px;
  color: #d5dbe8;
}

:deep(.muted) {
  font-size: 11px;
  color: #7c849b;
}

:deep(.tag-row) {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: nowrap;
  overflow: hidden;
}

:deep(.cell-stats) {
  display: flex;
  gap: 8px;
  font-size: 11px;
  color: #a9b1c6;
  white-space: nowrap;
}
</style>
