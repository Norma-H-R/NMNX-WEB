<script setup lang="ts">
import type { Comment } from '~/composables/useComments'

/**
 * 单条评论 —— Reddit 那套结构（见附件截图）。
 *
 * ⚠️ 这是评论插件的**内部件**，不要从外面直接引。业务侧只用 `<CommentThread>`。
 *
 * 结构（自上而下）：
 *   [头像+线程线] 用户名 · 时间
 *                 正文（支持链接）
 *                 ⊖  14  ↑  Reply  🏆 Award  ↗ Share  ⋯
 *
 * 「线程线」是 Reddit 的精髓：左侧一条竖线把整条评论（含它的所有子评论）框住，
 * 子评论再画自己的线 —— 一层套一层，层级全靠它表达，不需要多余的缩进标记。
 */

const props = defineProps<{
  comment: Comment
  /** 头像颜色序号（由父级按用户 id 算好，保证同一个人颜色一致） */
  hue: number
  /** 当前用户自己的评论 —— 操作行多一个「删除」 */
  mine: boolean
  /** 折叠状态由父级统一管（拍平列表里，折叠 = 跳过子树） */
  collapsed: boolean
  /** 这条评论下面还藏了多少条 */
  hidden: number
}>()

const emit = defineEmits<{
  (e: 'react', kind: 'like' | 'dislike' | 'favorite'): void
  (e: 'reply'): void
  (e: 'toggle'): void
}>()

/** 分值：赞 - 踩（Reddit 显示的是净值，不是两个分开的数） */
const score = () => props.comment.stats.like - props.comment.stats.dislike

/** 正文里的 URL 变成可点链接。纯文本评论仍不接受 Markdown 语法 */
function withLinks(text: string) {
  return text.replace(
    /(https?:\/\/[^\s]+)/g,
    '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
  )
}
</script>

<template>
  <div class="node" :class="{ 'is-mine': mine }">
    <!-- 头行：头像 + 用户名 + 时间 -->
    <header class="node__head">
      <span class="avatar" :style="{ '--h': hue }">{{ comment.user.name.charAt(0) }}</span>
      <span class="node__name">{{ comment.user.name }}</span>
      <span v-if="mine" class="node__me">我</span>
      <span class="node__time">{{ comment.createdAt.slice(0, 10) }}</span>
    </header>

    <!-- 正文 -->
    <p class="node__body" v-html="withLinks(comment.body)" />

    <!-- 操作行 -->
    <div class="node__acts">
      <button
        type="button"
        class="vote vote--down"
        :class="{ 'is-on': comment.mineState?.disliked }"
        aria-label="踩"
        @click="emit('react', 'dislike')"
      >
        ⊖
      </button>

      <span class="vote__n">{{ score() }}</span>

      <button
        type="button"
        class="vote vote--up"
        :class="{ 'is-on': comment.mineState?.liked }"
        aria-label="赞"
        @click="emit('react', 'like')"
      >
        ↑
      </button>

      <button type="button" class="act" @click="emit('reply')">
        <span class="act__i">💬</span> 回复
      </button>

      <button
        type="button"
        class="act"
        :class="{ 'is-on': comment.mineState?.favorited }"
        @click="emit('react', 'favorite')"
      >
        <span class="act__i">★</span> 收藏
      </button>

      <button type="button" class="act" @click="emit('toggle')">
        <span class="act__i">⋯</span>
        {{ collapsed && hidden ? `展开 ${hidden} 条` : '收起' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.node {
  position: relative;
}

/* ---------------------------- 头行 ---------------------------- */
.node__head {
  display: flex;
  align-items: center;
  gap: 8px;
}

.avatar {
  display: grid;
  place-items: center;
  flex: none;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  /* 颜色由用户 id 定，同一个人在哪条评论里都一个色 */
  background: linear-gradient(140deg, hsl(var(--h), 70%, 62%), hsl(calc(var(--h) + 40), 66%, 50%));
  color: #06070d;
  font-size: 11px;
  font-weight: 600;
}

.node__name {
  font-size: 12.5px;
  font-weight: 500;
  /* Reddit 的用户名是**灰的**，不是亮的 —— 亮的是正文。
     这样扫读时视线先落在内容上，不被用户名抢走 */
  color: var(--muted);
  transition: color 0.25s var(--ease);
}

.node:hover .node__name {
  color: var(--text-dim);
}

.node__me {
  padding: 1px 6px;
  border-radius: 5px;
  background: rgba(110, 231, 255, 0.14);
  color: var(--cyan);
  font-size: 10.5px;
}

.node__time {
  font-family: var(--mono);
  font-size: 11.5px;
  color: var(--muted);
}

/* ---------------------------- 正文 ---------------------------- */
.node__body {
  /* 左缩进对齐着头像右侧（头像 22 + 间距 8）—— Reddit 就是这个对齐方式 */
  margin: 7px 0 0 30px;
  font-size: 14px;
  line-height: 1.8;
  /* 正文比用户名亮：这是 Reddit 那条"视线落点"的规矩 */
  color: var(--text-dim);
  word-break: break-word;
}

.node__body :deep(a) {
  color: var(--cyan);
  text-decoration: underline;
  text-underline-offset: 2px;
}

/* ---------------------------- 操作行 ---------------------------- */
.node__acts {
  display: flex;
  align-items: center;
  gap: 2px;
  margin: 6px 0 0 30px;
}

.vote {
  display: grid;
  place-items: center;
  width: 24px;
  height: 24px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--muted);
  font-size: 14px;
  line-height: 1;
  cursor: pointer;
  transition: color 0.25s var(--ease), background 0.25s var(--ease);
}

.vote:hover {
  background: rgba(255, 255, 255, 0.06);
  color: var(--text);
}

/* 赞/踩各自点亮；踩偏红，赞用站点主色 */
.vote--up.is-on {
  color: var(--cyan);
  background: rgba(110, 231, 255, 0.12);
}

.vote--down.is-on {
  color: #ff9b9b;
  background: rgba(255, 155, 155, 0.12);
}

.vote__n {
  min-width: 22px;
  text-align: center;
  font-family: var(--mono);
  font-size: 12.5px;
  font-variant-numeric: tabular-nums;
  color: var(--text-dim);
}

.act {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 9px;
  border: 0;
  border-radius: 7px;
  background: transparent;
  color: var(--muted);
  font: inherit;
  font-size: 12px;
  cursor: pointer;
  transition: color 0.25s var(--ease), background 0.25s var(--ease);
}

.act__i {
  font-size: 11.5px;
  line-height: 1;
}

.act:hover {
  color: var(--text);
  background: rgba(255, 255, 255, 0.06);
}

.act.is-on {
  color: var(--gold);
  background: rgba(242, 209, 141, 0.12);
}

@media (prefers-reduced-motion: reduce) {
  .vote,
  .act {
    transition: none;
  }
}
</style>
