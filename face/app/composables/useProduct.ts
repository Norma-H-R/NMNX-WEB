/**
 * 产品数据层（**当前是假数据**）。
 *
 * ⚠️ 和别的假数据一样：**不许用 `Math.random()`** —— 服务端渲染一遍、客户端
 *    hydration 再一遍，两次结果差一点就撞 hydration。统一走 `seeded()`。
 *
 * 接口（待后端实现，参考 content 模块的公开读）：
 *   GET /api/v1/public/products          → fetchProducts()
 *   GET /api/v1/public/products/{slug}   → fetchProduct(slug)
 */

function seeded(seed: number) {
  let s = seed >>> 0

  return () => {
    s = (s + 0x6d2b79f5) >>> 0
    let t = Math.imul(s ^ (s >>> 15), 1 | s)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

export interface Product {
  id: number
  slug: string
  name: string
  /** 详情页顶部那个"下面是版本号"用的就是它 */
  version: string
  tagline: string
  status: 'stable' | 'beta' | 'planned'
  tags: string[]
  /** Markdown 介绍，详情页正文 */
  body: string
  /** 主题色相 —— 多层错开动效的基色 */
  hue: number
  updatedAt: string
}

interface Seed {
  name: string
  slug: string
  version: string
  tagline: string
  status: Product['status']
  tags: string[]
  hue: number
}

/** 10 个产品，从上到下就是这个顺序 */
const SEEDS: Seed[] = [
  {
    name: '主控 EA',
    slug: 'master-ea',
    version: '7.1.2',
    tagline: '信号生成与下单执行的核心，把开仓确认压到一帧内。',
    status: 'stable',
    tags: ['核心', '交易'],
    hue: 192,
  },
  {
    name: '跟随端',
    slug: 'follower',
    version: '7.1.0',
    tagline: '把主控的信号同步到多个账户，断线自动补偿。',
    status: 'stable',
    tags: ['多机', '同步'],
    hue: 210,
  },
  {
    name: '回测引擎',
    slug: 'backtest',
    version: '3.4.1',
    tagline: '同一套策略跑历史数据，支持逐笔与分钟级。',
    status: 'stable',
    tags: ['回测', '研究'],
    hue: 262,
  },
  {
    name: '信号网关',
    slug: 'signal-gateway',
    version: '2.8.0',
    tagline: '外部信号进来的唯一入口，做去重、限频与优先级。',
    status: 'stable',
    tags: ['网关', '风控'],
    hue: 168,
  },
  {
    name: '风控闸',
    slug: 'risk-gate',
    version: '4.0.3',
    tagline: '单笔上限、日内回撤、连续亏损熔断，三道闸一起管。',
    status: 'stable',
    tags: ['风控', '资金'],
    hue: 12,
  },
  {
    name: '点阵看板',
    slug: 'matrix-board',
    version: '1.9.0',
    tagline: '把仓位与盈亏铺成点阵，远处一眼看清状态。',
    status: 'beta',
    tags: ['可视化', '监控'],
    hue: 285,
  },
  {
    name: '授权中心',
    slug: 'license-center',
    version: '5.2.0',
    tagline: '激活、换机、席位管理，含离线宽限期。',
    status: 'stable',
    tags: ['授权', '设备'],
    hue: 42,
  },
  {
    name: '日志探针',
    slug: 'log-probe',
    version: '0.9.4',
    tagline: '采样式记录关键路径，出问题时能还原现场。',
    status: 'beta',
    tags: ['诊断', '采样'],
    hue: 145,
  },
  {
    name: '数据同步器',
    slug: 'data-sync',
    version: '2.1.6',
    tagline: '主控与跟随端之间的状态对账，自动修复不一致。',
    status: 'stable',
    tags: ['一致性', '对账'],
    hue: 226,
  },
  {
    name: '报表工坊',
    slug: 'report-studio',
    version: '1.0.0',
    tagline: '把成交、滑点、回撤拼成可分享的周期报告。',
    status: 'planned',
    tags: ['报表', '分享'],
    hue: 318,
  },
]

const STATUS_TEXT: Record<Product['status'], string> = {
  stable: '稳定版',
  beta: '测试版',
  planned: '规划中',
}

export const statusText = (s: Product['status']) => STATUS_TEXT[s]

function bodyOf(s: Seed) {
  return [
    `## ${s.name}是做什么的`,
    '',
    s.tagline,
    '',
    '## 关键点',
    '',
    '- 与主控保持同一套信号口径，不做二次解释',
    '- 所有对外动作都有超时与重试，失败不会静默',
    '- 配置改动即时生效，不用重启',
    '',
    '## 兼容性',
    '',
    '| 项目 | 要求 |',
    '|---|---|',
    '| 主控 EA | ≥ 7.0 |',
    `| 版本 | ${s.version} |`,
    '| 系统 | Windows 10 / Server 2019 以上 |',
    '',
    '> 升级前建议先在测试账户上跑一轮，确认信号对齐再切实盘。',
  ].join('\n')
}

const PRODUCTS: Product[] = SEEDS.map((s, i) => {
  const rnd = seeded(1000 + i * 37)
  const day = 1 + Math.floor(rnd() * 28)

  return {
    id: i + 1,
    slug: s.slug,
    name: s.name,
    version: s.version,
    tagline: s.tagline,
    status: s.status,
    tags: s.tags,
    body: bodyOf(s),
    hue: s.hue,
    updatedAt: `2026-09-${String(day).padStart(2, '0')}`,
  }
})

/** ← GET /api/v1/public/products */
export function fetchProducts(): Product[] {
  return PRODUCTS
}

/** ← GET /api/v1/public/products/{slug} */
export function fetchProduct(slug: string): Product | null {
  return PRODUCTS.find((p) => p.slug === slug) ?? null
}
