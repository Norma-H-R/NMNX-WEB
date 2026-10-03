<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { marked } from 'marked'
import type { Blog } from '~/composables/useBlog'

/**
 * 博客编辑器 —— 新建与编辑共用。
 *
 * ⚠️ **这一版是自建的轻量编辑器**（textarea + 实时预览），不是文档里选定的
 *    `md-editor-v3`。原因：那个库依赖 DOM，在 Nuxt 里必须用 `<ClientOnly>` 包一层，
 *    而本轮先把「50 篇 + 100 条评论」的效果跑通更重要。
 *    **换它只动这个文件**（页面不用动）—— 已记进 10-blog.md 的待办。
 *
 * 正文一直是 Markdown 源（对应后端的 `body_md`），预览只是拿 marked 渲一遍，
 * 不参与存储 —— 这条不能反，否则"富文本再导出 MD"那套老问题会回来。
 */

/**
 * 新文章的初始正文骨架。
 *
 * ⚠️ 必须放在 `<script setup>` 里、**且在用到它的那行之前**：`const` 有 TDZ，
 *    塞到后面的 `<script>` 块里会在 setup 顶层直接抛 ReferenceError。
 *    用数组 join 而不是模板字符串，省得正文里的代码围栏还要转义。
 */
const DEFAULT_BODY = [
  '## 先说结论',
  '',
  '把最要紧的一句放这里。',
  '',
  '## 过程',
  '',
  '1. 第一步',
  '2. 第二步',
  '',
  '## 数据',
  '',
  '| 场景 | 改前 | 改后 |',
  '|---|---|---|',
  '| 常规 | 18ms | 17ms |',
  '',
  '> 引用写在这里。',
  '',
  '```js',
  '// 代码块',
  'const x = 1',
  '```',
].join('\n')

const props = defineProps<{
  /** 传了就是编辑已有，不传就是新建 */
  blog?: Blog | null
}>()

const title = ref(props.blog?.title ?? '')
const slug = ref(props.blog?.slug ?? '')
const tags = ref((props.blog?.tags ?? []).join(', '))
const body = ref(props.blog?.body ?? DEFAULT_BODY)
const cover = ref<string | null>(props.blog?.cover ?? null)

const preview = ref(false)
const slugTouched = ref(!!props.blog)

/** 中文标题这里只能生成个占位：正式是**后端**用拼音库转（overtrue/pinyin） */
watch(title, (v) => {
  if (slugTouched.value) return
  slug.value = v.trim()
    ? `blog-${v.trim().length}${v.trim().slice(0, 2)}`
    : ''
})

const DRAFT_KEY = 'blog-draft-key'

const previewHtml = computed(() => marked.parse(body.value, { async: false }) as string)

const wordCount = computed(() => body.value.replace(/\s/g, '').length)

const tagList = computed(() =>
  tags.value
    .split(/[,，\s]+/)
    .map((t) => t.trim())
    .filter(Boolean)
    .slice(0, 5),
)

function onCover(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return

  // 本地预览用；真上传要走 POST /api/v1/member/uploads
  cover.value = URL.createObjectURL(file)
}

function submit(kind: 'draft' | 'publish') {
  if (!title.value.trim() || !body.value.trim()) return

  // 假保存：把状态写进 localStorage，好确认交互走通。
  // 正式环境是 POST/PUT /api/v1/member/blogs，封面服务端校验（决策 8）
  try {
    localStorage.setItem(
      DRAFT_KEY,
      JSON.stringify({ title: title.value, slug: slug.value, tags: tagList.value, status: kind }),
    )
  } catch {
    // 存不进去就算了
  }

  alert(kind === 'draft' ? '已存草稿（当前是假保存）' : '已发布（当前是假保存）')
}
</script>

<template>
  <div class="ed">
    <!-- 顶部：标题 -->
    <input v-model="title" class="ed__title" placeholder="标题" maxlength="160" />

    <div class="ed__row">
      <label class="fld fld--grow">
        <span class="fld__k">网址别名（slug）</span>
        <input
          v-model="slug"
          class="fld__i fld__i--mono"
          placeholder="留空则自动生成"
          @input="slugTouched = true"
        />
      </label>

      <label class="fld fld--grow">
        <span class="fld__k">标签（最多 5 个，逗号分隔）</span>
        <input v-model="tags" class="fld__i" placeholder="实测, 性能" />
      </label>
    </div>

    <!-- 封面：强制上传；没传就显示占位块 -->
    <div class="fld">
      <span class="fld__k">封面（必填，发布时校验）</span>
      <div class="cover" :class="{ 'is-empty': !cover }">
        <img v-if="cover" :src="cover" alt="" />
        <span v-else class="ph" aria-hidden="true" />

        <label class="cover__pick">
          <input type="file" accept="image/*" hidden @change="onCover" />
          <span>{{ cover ? '换一张' : '选择图片' }}</span>
        </label>
      </div>
    </div>

    <!-- 正文 -->
    <div class="ed__body">
      <div class="ed__bar">
        <div class="tabs">
          <button type="button" class="tab" :class="{ 'is-on': !preview }" @click="preview = false">
            编辑
          </button>
          <button type="button" class="tab" :class="{ 'is-on': preview }" @click="preview = true">
            预览
          </button>
        </div>

        <span class="ed__meta">{{ wordCount }} 字 · Markdown</span>
      </div>

      <textarea v-if="!preview" v-model="body" class="ed__ta" spellcheck="false" />
      <div v-else class="prose" v-html="previewHtml" />
    </div>

    <!-- 附件：真实现是走上传接口，再按 kind 渲染卡片 -->
    <div class="ed__atts">
      <span class="fld__k">附件（图片 / 音频 / 视频 / doc / xls / pdf）</span>
      <p class="ed__hint">
        正式版：点上传 → POST /api/v1/member/uploads → 后端按 MIME 判 kind → 正文里插链接。
      </p>
    </div>

    <!-- 底部操作 -->
    <div class="ed__foot">
      <span class="ed__tip">封面没传也能存草稿，但发布时会拦下来</span>

      <div class="ed__acts">
        <button type="button" class="btn btn--ghost" @click="submit('draft')">存草稿</button>
        <button type="button" class="btn" @click="submit('publish')">发布</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ed {
  margin-top: clamp(20px, 2.2vw, 28px);
}

.ed__title {
  display: block;
  width: 100%;
  padding: 12px 0;
  border: 0;
  border-bottom: 1px solid var(--line-strong);
  background: transparent;
  color: var(--text);
  font: inherit;
  font-size: clamp(20px, 2.4vw, 26px);
  font-weight: 500;
}

.ed__title:focus {
  outline: none;
  border-color: rgba(110, 231, 255, 0.5);
}

.ed__row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 14px;
  margin-top: 18px;
}

.fld {
  display: block;
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

.fld__i--mono {
  font-family: var(--mono);
  letter-spacing: 0.04em;
}

.fld__i:focus {
  outline: none;
  border-color: rgba(110, 231, 255, 0.45);
}

/* ---------------------------- 封面 ---------------------------- */
.cover {
  position: relative;
  margin-top: 6px;
  aspect-ratio: 16 / 9;
  max-width: 420px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: #0b0f1a;
  overflow: hidden;
}

.cover img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.cover .ph {
  display: block;
  width: 100%;
  height: 100%;
  background-image: repeating-linear-gradient(
    -45deg,
    rgba(255, 255, 255, 0.045),
    rgba(255, 255, 255, 0.045) 10px,
    transparent 10px,
    transparent 20px
  );
}

.cover__pick {
  position: absolute;
  inset: auto 10px 10px auto;
  padding: 6px 14px;
  border: 1px solid var(--line-strong);
  border-radius: 99px;
  background: rgba(6, 7, 13, 0.75);
  color: var(--text-dim);
  font-size: 12px;
  cursor: pointer;
  transition: color 0.3s var(--ease), border-color 0.3s var(--ease);
}

.cover__pick:hover {
  color: var(--cyan);
  border-color: var(--cyan);
}

/* ---------------------------- 正文 ---------------------------- */
.ed__body {
  margin-top: 22px;
}

.ed__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
}

.tabs {
  display: inline-flex;
  gap: 4px;
  padding: 3px;
  border: 1px solid var(--line);
  border-radius: 99px;
  background: rgba(255, 255, 255, 0.03);
}

.tab {
  padding: 6px 16px;
  border: 0;
  border-radius: 99px;
  background: transparent;
  color: var(--muted);
  font: inherit;
  font-size: 12.5px;
  cursor: pointer;
  transition: color 0.3s var(--ease), background 0.4s var(--ease);
}

.tab.is-on {
  color: #06070d;
  background: var(--grad);
}

.ed__meta {
  font-family: var(--mono);
  font-size: 11.5px;
  color: var(--muted);
}

.ed__ta {
  display: block;
  width: 100%;
  min-height: 420px;
  padding: 14px 16px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: rgba(0, 0, 0, 0.28);
  color: var(--text-dim);
  font-family: var(--mono);
  font-size: 13.5px;
  line-height: 1.85;
  resize: vertical;
}

.ed__ta:focus {
  outline: none;
  border-color: rgba(110, 231, 255, 0.45);
}

/* 预览区沿用正文那套样式（简版） */
.prose {
  min-height: 420px;
  padding: 14px 16px;
  border: 1px solid var(--line);
  border-radius: 12px;
  font-size: 14.5px;
  line-height: 1.9;
  color: var(--text-dim);
}

.prose :deep(h2) {
  margin: 22px 0 10px;
  font-size: 17px;
  color: var(--text);
}

.prose :deep(p) {
  margin: 10px 0;
}

.prose :deep(blockquote) {
  margin: 14px 0;
  padding: 10px 14px;
  border-left: 2px solid var(--cyan);
  background: rgba(110, 231, 255, 0.05);
  border-radius: 0 8px 8px 0;
}

.prose :deep(code) {
  padding: 2px 6px;
  border-radius: 5px;
  background: rgba(255, 255, 255, 0.07);
  font-family: var(--mono);
  font-size: 12.5px;
  color: var(--cyan);
}

.prose :deep(pre) {
  margin: 14px 0;
  padding: 12px 14px;
  border: 1px solid var(--line);
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.35);
  overflow-x: auto;
}

.prose :deep(pre code) {
  padding: 0;
  background: transparent;
}

.prose :deep(table) {
  width: 100%;
  margin: 14px 0;
  border-collapse: collapse;
  font-size: 13px;
}

.prose :deep(th),
.prose :deep(td) {
  padding: 8px 10px;
  border: 1px solid var(--line);
  text-align: left;
}

/* ---------------------------- 附件与底部 ---------------------------- */
.ed__atts {
  margin-top: 20px;
  padding: 14px;
  border: 1px dashed var(--line-strong);
  border-radius: 12px;
}

.ed__hint {
  margin-top: 8px;
  font-size: 12px;
  line-height: 1.7;
  color: var(--muted);
}

.ed__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 14px;
  margin-top: 24px;
  padding-top: 18px;
  border-top: 1px solid var(--line);
}

.ed__tip {
  font-size: 12px;
  color: var(--muted);
}

.ed__acts {
  display: flex;
  gap: 10px;
}

@media (prefers-reduced-motion: reduce) {
  .tab,
  .cover__pick {
    transition: none;
  }
}
</style>
