<script setup lang="ts">
import { computed, ref } from 'vue'
import { marked } from 'marked'
import { BOARDS } from '~/composables/useForum'

/**
 * 发帖（/forum/new）。
 *
 * ⚠️ **半成品**：这一版只有表单与预览，**没有接上传、没有接接口、没做登录拦截**
 *    （已记进 core/docs/modules/11-forum.md 的待办）。
 *    正文是 Markdown，发布时该进的是 `body_md`，和博客一个规矩。
 *
 * 正式环境要 `auth:member`；发布走 `POST /api/v1/member/forum/posts`。
 */

const boardId = ref(BOARDS[1]!.id)
const title = ref('')
const body = ref('## 现象\n\n把遇到的问题写清楚。\n\n## 我试过的\n\n1. \n2. \n')
const preview = ref(false)

const previewHtml = computed(() => marked.parse(body.value, { async: false }) as string)
const canPost = computed(() => title.value.trim().length > 0 && body.value.trim().length > 0)

function submit() {
  if (!canPost.value) return

  // 假发布：真实环境是 POST /api/v1/member/forum/posts，成功后跳帖子详情
  alert(`已发布到「${BOARDS.find((b) => b.id === boardId.value)?.name}」（当前是假保存）`)
}

useHead({ title: '发帖 · 论坛' })
</script>

<template>
  <section class="section">
    <BackFab fallback="/forum" label="返回论坛" />

    <div class="container narrow">
      <p class="eyebrow">发帖</p>
      <h1 class="title">说清楚<em>就好</em></h1>

      <div class="form">
        <label class="fld">
          <span class="fld__k">发到哪个版</span>
          <select v-model.number="boardId" class="fld__i">
            <option v-for="b in BOARDS" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </label>

        <label class="fld">
          <span class="fld__k">标题</span>
          <input v-model="title" class="fld__i" placeholder="一句话说清问题或主题" maxlength="160" />
        </label>

        <div class="fld">
          <div class="bar">
            <span class="fld__k">正文（Markdown）</span>
            <div class="tabs">
              <button type="button" class="tab" :class="{ 'is-on': !preview }" @click="preview = false">
                编辑
              </button>
              <button type="button" class="tab" :class="{ 'is-on': preview }" @click="preview = true">
                预览
              </button>
            </div>
          </div>

          <textarea v-if="!preview" v-model="body" class="ta" spellcheck="false" />
          <div v-else class="prose" v-html="previewHtml" />
        </div>

        <div class="foot">
          <span class="tip">发帖前搜一下，也许已经有人问过</span>
          <button type="button" class="btn" :disabled="!canPost" @click="submit">发布</button>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.narrow {
  max-width: 860px;
}

.form {
  margin-top: clamp(20px, 2.2vw, 28px);
}

.fld {
  display: block;
}

.fld + .fld,
.fld + div.fld {
  margin-top: 18px;
}

.fld__k {
  display: block;
  margin-bottom: 7px;
  font-size: 11.5px;
  letter-spacing: 0.14em;
  color: var(--muted);
}

.fld__i {
  display: block;
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--line);
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.25);
  color: var(--text);
  font: inherit;
  font-size: 13.5px;
}

.fld__i:focus {
  outline: none;
  border-color: rgba(110, 231, 255, 0.45);
}

.bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.bar .fld__k {
  margin-bottom: 0;
}

.tabs {
  display: inline-flex;
  gap: 3px;
  padding: 3px;
  border: 1px solid var(--line);
  border-radius: 99px;
}

.tab {
  padding: 5px 14px;
  border: 0;
  border-radius: 99px;
  background: transparent;
  color: var(--muted);
  font: inherit;
  font-size: 12px;
  cursor: pointer;
}

.tab.is-on {
  color: #06070d;
  background: var(--grad);
}

.ta {
  display: block;
  width: 100%;
  min-height: 300px;
  margin-top: 10px;
  padding: 13px 15px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: rgba(0, 0, 0, 0.28);
  color: var(--text-dim);
  font-family: var(--mono);
  font-size: 13px;
  line-height: 1.8;
  resize: vertical;
}

.ta:focus {
  outline: none;
  border-color: rgba(110, 231, 255, 0.45);
}

.prose {
  min-height: 300px;
  margin-top: 10px;
  padding: 13px 15px;
  border: 1px solid var(--line);
  border-radius: 12px;
  font-size: 14px;
  line-height: 1.9;
  color: var(--text-dim);
}

.prose :deep(h2) {
  margin: 20px 0 10px;
  font-size: 17px;
  color: var(--text);
}

.prose :deep(blockquote) {
  margin: 14px 0;
  padding: 10px 14px;
  border-left: 2px solid var(--cyan);
  background: rgba(110, 231, 255, 0.05);
  border-radius: 0 8px 8px 0;
}

.foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 14px;
  margin-top: 22px;
  padding-top: 18px;
  border-top: 1px solid var(--line);
}

.tip {
  font-size: 12px;
  color: var(--muted);
}

.btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
</style>
