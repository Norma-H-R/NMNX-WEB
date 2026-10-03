<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { currentMemberId, fetchComments, type Comment } from '~/composables/useComments'
import CommentNode from '~/components/comment/CommentNode.vue'

/**
 * 评论区（**插件主入口**）—— 挂到任何对象下面。
 *
 * ⚠️ 业务侧只需要传两个 props：`targetType` / `targetId`，**不认识"博客"这个词**。
 *    论坛、文章详情将来都只是换一个 `targetType` 再插一次，代码一行不用改。
 *    （见 core/docs/modules/10-blog.md 决策 9）
 *
 * 视觉照 Reddit：一行 `排序 [最佳▾] + [⌕ 搜索评论]` 工具条；每条评论左侧一条
 * **线程线**把它的整棵子树框住，子评论再画自己的线 —— 层级全靠它，不靠缩进标记。
 *
 * ⚠️ 递归靠**自引用组件名**（`<CommentThread>` 出现在自己的模板里），
 *    Vue 靠文件名解析，不需要 `export default { name }`；
 *    这里也**不能**再写第二个 `<script>` 块 —— 那会把 setup 的属性收进 `$setup`，
 *    父级传 `nodes` 就会失效。
 *
 * 两个配合递归用的 props：
 *   · `nodes`     —— 子层直接传子树；**顶层不传**，由 `targetType/targetId` 自己取
 *   · `isChild`   —— 告诉渲染"我现在是某一层的子树"（决定要不要画工具条、线）
 */

const props = withDefaults(
  defineProps<{
    /** 目标类型：`blog` / `forum_post` / `article`… */
    targetType?: string
    targetId?: number
    /** 子层传入的子树；顶层留空 */
    nodes?: Comment[]
    /** 是不是子层（由父层显式传，避免用 nodes 反推） */
    isChild?: boolean
    /** 最多渲染几层 */
    maxDepth?: number
  }>(),
  { targetType: '', targetId: 0, nodes: undefined, isChild: false, maxDepth: 6 },
)

type Kind = 'like' | 'dislike' | 'favorite'

const emit = defineEmits<{
  (e: 'react', node: Comment, kind: Kind): void
  (e: 'reply', node: Comment): void
}>()

// ---------------------------------------------------------------------------
// 数据：顶层自己取；子层用父级给的
// ---------------------------------------------------------------------------
const own = ref<Comment[]>(
  props.nodes ?? (props.targetType ? fetchComments(props.targetType, props.targetId) : []),
)

const items = computed(() => props.nodes ?? own.value)

/** 折叠状态（每个实例只管自己这一层） */
const collapsed = reactive<Record<number, boolean>>({})

const sort = ref<'best' | 'new' | 'old'>('best')
const keyword = ref('')
const draft = ref('')
const replyTo = ref<Comment | null>(null)

// ---------------------------------------------------------------------------
// 排序 / 搜索 / 计数
// ---------------------------------------------------------------------------
/** 分值 = 赞 - 踩，与画面上显示的那个数字一个口径 */
const score = (c: Comment) => c.stats.like - c.stats.dislike

function countAll(list: Comment[]): number {
  let n = 0
  const walk = (l: Comment[]) => l.forEach((c) => (n += 1, walk(c.children)))
  walk(list)

  return n
}

function descendants(c: Comment): number {
  return countAll(c.children)
}

/** 搜索：命中的留下，**祖先也一并保留**（否则回复会变成孤儿） */
function filterTree(list: Comment[], kw: string): Comment[] {
  if (!kw) return list

  const walk = (items2: Comment[]): Comment[] =>
    items2
      .map((c) => {
        const kids = walk(c.children)
        const hit = c.body.toLowerCase().includes(kw) || c.user.name.toLowerCase().includes(kw)

        return hit || kids.length ? { ...c, children: kids } : null
      })
      .filter((c): c is Comment => c !== null)

  return walk(list)
}

function compare(a: Comment, b: Comment) {
  if (sort.value === 'new') return b.createdAt.localeCompare(a.createdAt)
  if (sort.value === 'old') return a.createdAt.localeCompare(b.createdAt)

  return score(b) - score(a)
}

const total = computed(() => countAll(items.value))

const visible = computed(() =>
  filterTree([...items.value].sort(compare), keyword.value.trim().toLowerCase()),
)

// ---------------------------------------------------------------------------
// 交互：统一往上传，由**顶层**那一份数据来改 —— 子层不各自持有一份状态
// ---------------------------------------------------------------------------
const onReact = (node: Comment, kind: Kind) => emit('react', node, kind)
const onReply = (node: Comment) => {
  if (props.isChild) {
    emit('reply', node)

    return
  }

  replyTo.value = node
  nextTick(() => document.getElementById('ct-input')?.focus())
}

function toggle(node: Comment) {
  collapsed[node.id] = !collapsed[node.id]
}

function submit() {
  const body = draft.value.trim()
  if (!body) return

  const parent = replyTo.value
  const next: Comment = {
    id: Date.now(),
    parentId: parent ? parent.id : null,
    depth: parent ? parent.depth + 1 : 1,
    path: '',
    user: { id: currentMemberId(), name: '我' },
    body,
    stats: { like: 0, dislike: 0, favorite: 0, reply: 0 },
    createdAt: new Date().toISOString(),
    children: [],
    mineState: {},
  }

  if (parent) {
    parent.children.unshift(next)
    parent.stats.reply += 1
    collapsed[parent.id] = false
  } else {
    own.value.unshift(next)
  }

  draft.value = ''
  replyTo.value = null
}

const replyName = computed(() => replyTo.value?.user.name ?? '')
</script>

<template>
  <!-- 只有顶层带 id：子层也带的话会撞 id（也顺便给 #comments 一个落脚点） -->
  <div class="ct" :id="isChild ? undefined : 'comments'" :class="{ 'is-kids': isChild }">
    <!-- 工具条：只有顶层画 -->
    <div v-if="!isChild" class="ct__bar">
      <label class="sort">
        <span class="sort__k">排序</span>
        <select v-model="sort" class="sort__s">
          <option value="best">最佳</option>
          <option value="new">最新</option>
          <option value="old">最早</option>
        </select>
      </label>

      <label class="search">
        <span class="search__i" aria-hidden="true">⌕</span>
        <input v-model="keyword" class="search__in" placeholder="搜索评论" />
      </label>

      <span class="ct__n">{{ total }} 条</span>
    </div>

    <!-- 发表框：只有顶层画（子层的"回复"会滚到顶层那个框里） -->
    <div v-if="!isChild" class="box">
      <span class="box__avatar">我</span>

      <div class="box__main">
        <p v-if="replyTo" class="box__to">
          回复 <b>{{ replyName }}</b>
          <button type="button" class="box__cancel" @click="replyTo = null">取消</button>
        </p>

        <textarea
          id="ct-input"
          v-model="draft"
          class="box__ta"
          rows="2"
          :placeholder="replyTo ? `回复 ${replyName}…` : '写点你的看法…'"
        />

        <div class="box__foot">
          <span class="box__tip">纯文本，支持贴链接</span>
          <button type="button" class="box__ok" :disabled="!draft.trim()" @click="submit">
            发表
          </button>
        </div>
      </div>
    </div>

    <!-- 这一层的评论 -->
    <div v-if="visible.length" class="ct__list">
      <div v-for="c in visible" :key="c.id" class="th">
        <CommentNode
          :comment="c"
          :hue="(c.user.id * 47) % 360"
          :mine="c.user.id === currentMemberId()"
          :collapsed="!!collapsed[c.id]"
          :hidden="descendants(c)"
          @react="(k) => onReact(c, k)"
          @reply="onReply(c)"
          @toggle="toggle(c)"
        />

        <!-- 子树：递归自己；折叠就整个不渲染 -->
        <div v-if="!collapsed[c.id] && c.children.length" class="th__kids">
          <CommentThread
            :nodes="c.children"
            :is-child="true"
            :max-depth="maxDepth - 1"
            @react="onReact"
            @reply="onReply"
          />
        </div>
      </div>
    </div>

    <p v-else-if="!isChild" class="ct__empty">还没有评论，来说第一句。</p>
  </div>
</template>

<style scoped>
.ct.is-kids {
  margin-top: 0;
}

/* ---------------------------- 工具条 ---------------------------- */
.ct__bar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 14px;
}

.sort {
  display: inline-flex;
  align-items: center;
  gap: 7px;
}

.sort__k {
  font-size: 12.5px;
  color: var(--muted);
}

.sort__s {
  padding: 6px 12px;
  border: 1px solid var(--line-strong);
  border-radius: 99px;
  background: rgba(255, 255, 255, 0.04);
  color: var(--text);
  font: inherit;
  font-size: 12.5px;
  cursor: pointer;
}

.sort__s:focus {
  outline: none;
  border-color: rgba(110, 231, 255, 0.5);
}

.search {
  position: relative;
  display: inline-flex;
  align-items: center;
  flex: 1;
  min-width: 180px;
  max-width: 320px;
}

.search__i {
  position: absolute;
  left: 12px;
  font-size: 13px;
  color: var(--muted);
}

.search__in {
  width: 100%;
  padding: 7px 12px 7px 30px;
  border: 1px solid var(--line-strong);
  border-radius: 99px;
  background: rgba(255, 255, 255, 0.04);
  color: var(--text);
  font: inherit;
  font-size: 12.5px;
}

.search__in:focus {
  outline: none;
  border-color: rgba(110, 231, 255, 0.5);
}

.ct__n {
  margin-left: auto;
  font-family: var(--mono);
  font-size: 12px;
  color: var(--muted);
}

/* ---------------------------- 发表框 ---------------------------- */
.box {
  display: flex;
  gap: 10px;
  margin-bottom: 18px;
}

.box__avatar {
  display: grid;
  place-items: center;
  flex: none;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: var(--grad);
  color: #06070d;
  font-size: 11.5px;
  font-weight: 600;
}

.box__main {
  flex: 1;
  min-width: 0;
}

.box__to {
  margin-bottom: 6px;
  font-size: 12px;
  color: var(--muted);
}

.box__to b {
  color: var(--text-dim);
  font-weight: 500;
}

.box__cancel {
  margin-left: 8px;
  border: 0;
  background: transparent;
  color: var(--muted);
  font: inherit;
  font-size: 12px;
  cursor: pointer;
}

.box__cancel:hover {
  color: var(--cyan);
}

.box__ta {
  display: block;
  width: 100%;
  padding: 9px 12px;
  border: 1px solid var(--line-strong);
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.28);
  color: var(--text);
  font: inherit;
  font-size: 13.5px;
  line-height: 1.7;
  resize: vertical;
}

.box__ta:focus {
  outline: none;
  border-color: rgba(110, 231, 255, 0.5);
}

.box__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 8px;
}

.box__tip {
  font-size: 11.5px;
  color: var(--muted);
}

.box__ok {
  padding: 7px 18px;
  border: 0;
  border-radius: 99px;
  background: var(--grad);
  color: #06070d;
  font: inherit;
  font-size: 12.5px;
  font-weight: 500;
  cursor: pointer;
  transition: filter 0.3s var(--ease), opacity 0.3s var(--ease);
}

.box__ok:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.box__ok:not(:disabled):hover {
  filter: brightness(1.08);
}

/* ---------------------------- 列表与线程线 ---------------------------- */
.ct__list {
  display: grid;
  gap: 16px;
}

.ct.is-kids .ct__list {
  gap: 14px;
}

/*
 * 线程线画在"子树容器"上，而不是每条评论上 ——
 * 这样它天然把"这条评论的所有后代"框在一起，子层再画自己的线，一层套一层。
 */
.th__kids {
  position: relative;
  margin: 10px 0 0 30px;
  padding-left: 14px;
  border-left: 2px solid var(--line);
  transition: border-color 0.3s var(--ease);
}

.th__kids:hover {
  border-left-color: rgba(110, 231, 255, 0.35);
}

.ct__empty {
  padding: 22px 0;
  text-align: center;
  font-size: 13px;
  color: var(--muted);
}

@media (max-width: 640px) {
  .th__kids {
    margin-left: 14px;
    padding-left: 10px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .box__ok,
  .th__kids {
    transition: none;
  }
}
</style>
