<script setup>
// 二维码走模块引用而不是 public 绝对路径：
// public 里的文件会被原样拷贝并用 /qr.png 引用，站点一旦部署在子路径下
// 就会 404。交给打包器处理成带 hash 的资源引用才安全。
import qrUrl from '~/assets/qr.png'

const channels = [
  { k: '微信', v: 'XP863131' },
  { k: '授权', v: '按账号 / 经纪商 / 有效期发码' },
  { k: '交付', v: '一次编译，全量分发' },
]
</script>

<template>
  <section id="contact" class="section contact">
    <div class="container">
      <div class="contact__panel rv" v-reveal="{ y: 44, duration: 1.2 }">
        <div class="contact__text">
          <p class="eyebrow">联系</p>
          <h2 class="title">要一个自己的<br />激活码？</h2>
          <p class="lead">
            把账号、经纪商和期望的有效期发过来，我们生成对应的离线激活码。
            一次编译的程序可以分发给所有客户，不需要为每个人改代码。
          </p>

          <ul class="contact__list">
            <li v-for="c in channels" :key="c.k">
              <span>{{ c.k }}</span>{{ c.v }}
            </li>
          </ul>
        </div>

        <figure class="contact__qr">
          <img :src="qrUrl" alt="扫码联系" width="279" height="279" loading="lazy" />
          <figcaption>扫码联系</figcaption>
        </figure>
      </div>
    </div>
  </section>
</template>

<style scoped>
.contact__panel {
  position: relative;
  display: grid;
  /* 始终两列：左文右码，窄屏只压间距不塌成堆叠 */
  grid-template-columns: minmax(0, 1fr) auto;
  gap: clamp(20px, 5vw, 90px);
  align-items: center;
  padding: clamp(22px, 5vw, 76px);
  border: 1px solid var(--line);
  border-radius: 26px;
  background: linear-gradient(150deg, rgba(255, 255, 255, 0.05), rgba(255, 255, 255, 0.012));
  overflow: hidden;
}

.contact__panel::before {
  content: '';
  position: absolute;
  top: -40%;
  right: -10%;
  width: 46%;
  height: 180%;
  background: radial-gradient(closest-side, rgba(110, 231, 255, 0.11), transparent);
  pointer-events: none;
}

.contact__list {
  display: grid;
  gap: 16px;
  margin: 44px 0 0;
  padding: 0;
  list-style: none;
  font-size: 14.5px;
  color: var(--text-dim);
}

.contact__list span {
  display: inline-block;
  min-width: 62px;
  margin-right: 18px;
  font-size: 12px;
  letter-spacing: 0.22em;
  color: var(--muted);
}

.contact__qr {
  position: relative;
  margin: 0;
  padding: 16px 16px 12px;
  border: 1px solid var(--line);
  border-radius: 18px;
  background: #fff;
  box-shadow: 0 24px 70px -30px rgba(110, 231, 255, 0.5);
}

.contact__qr img {
  width: clamp(150px, 17vw, 210px);
  height: auto;
  border-radius: 8px;
}

.contact__qr figcaption {
  margin-top: 10px;
  text-align: center;
  font-size: 12px;
  letter-spacing: 0.24em;
  color: #6b7280;
}

/* 窄屏只把二维码内边距收掉，列数不动 —— 二维码太小会扫不出来 */
@media (max-width: 820px) {
  .contact__qr {
    padding: 10px 10px 8px;
  }
  .contact__qr img {
    width: clamp(118px, 26vw, 210px);
  }
  .contact__qr figcaption {
    font-size: 11px;
    letter-spacing: 0.12em;
  }
}
</style>
