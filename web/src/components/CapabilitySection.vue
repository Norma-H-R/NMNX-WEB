<script setup>
const items = [
  {
    no: '01',
    title: '主控',
    desc: '负责信号判定、仓位管理与下单。所有决策逻辑集中在一处，便于回测与版本管理。',
    tags: ['策略引擎', '风控内置'],
  },
  {
    no: '02',
    title: '跟随端',
    desc: '通过命名管道直驱终端，跳过轮询与文件落地，把指令在毫秒级送达每一台机器。',
    tags: ['命名管道', '多端同步'],
  },
  {
    no: '03',
    title: '授权',
    desc: '离线激活码，绑定账号、经纪商与有效期。一次编译分发所有客户，按需发码即可。',
    tags: ['离线校验', '按客户发码'],
  },
]

// 记录鼠标在卡片内的坐标，交给 CSS 的径向渐变做跟随柔光
function onMove(e) {
  const el = e.currentTarget
  const r = el.getBoundingClientRect()
  el.style.setProperty('--mx', `${e.clientX - r.left}px`)
  el.style.setProperty('--my', `${e.clientY - r.top}px`)
}
</script>

<template>
  <section id="capability" class="section cap">
    <div class="container">
      <div class="cap__head" v-reveal="{ selector: '.rv', stagger: 0.1 }">
        <p class="eyebrow rv">能力</p>
        <h2 class="title rv">三个部分，一条链路</h2>
      </div>

      <div class="cap__grid" v-reveal="{ selector: '.card', stagger: 0.12, y: 40 }">
        <article
          v-for="item in items"
          :key="item.no"
          class="card cap__card"
          @pointermove="onMove"
        >
          <span class="cap__no">{{ item.no }}</span>
          <h3 class="cap__title">{{ item.title }}</h3>
          <p class="cap__desc">{{ item.desc }}</p>
          <ul class="cap__tags">
            <li v-for="t in item.tags" :key="t">{{ t }}</li>
          </ul>
        </article>
      </div>
    </div>
  </section>
</template>

<style scoped>
.cap__head {
  max-width: 640px;
}

.cap__grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 20px;
  margin-top: clamp(48px, 7vw, 84px);
}

.cap__card {
  display: flex;
  flex-direction: column;
  min-height: 292px;
}

.cap__no {
  font-size: 12px;
  letter-spacing: 0.3em;
  color: var(--muted);
}

.cap__title {
  margin-top: 26px;
  font-size: 25px;
  font-weight: 400;
  letter-spacing: 0.04em;
}

.cap__desc {
  margin-top: 16px;
  font-size: 14.5px;
  line-height: 1.95;
  color: var(--text-dim);
}

.cap__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: auto 0 0;
  padding: 26px 0 0;
  list-style: none;
}

.cap__tags li {
  padding: 5px 13px;
  border: 1px solid var(--line);
  border-radius: 99px;
  font-size: 12px;
  letter-spacing: 0.06em;
  color: var(--muted);
  transition: color 0.4s var(--ease), border-color 0.4s var(--ease);
}

.cap__card:hover .cap__tags li {
  color: var(--text-dim);
  border-color: var(--line-strong);
}

@media (max-width: 940px) {
  .cap__grid {
    grid-template-columns: 1fr;
  }
  .cap__card {
    min-height: 0;
  }
}
</style>
