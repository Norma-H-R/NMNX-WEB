import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue2'

/**
 * 原仓库是 2019 年的 Vue CLI 3（webpack 4 + node-sass），在 Node 24 上装不起来。
 * 这里换成 Vite 5 + Vue 2.7 承载，源码保持上游原样。
 *
 * 兼容层一：上游 store.js 用 CommonJS 的 require('./assets/x.png') 引图片，
 * 那是 webpack 的能力，Vite 是纯 ESM，遇到 require 会直接报错，
 * 所以就地把它转成等价的 ESM import，不动上游源码。
 */
function cjsRequire () {
  return {
    name: 'limni-cjs-require',
    enforce: 'pre',
    transform (code, id) {
      if (!id.endsWith('.js') || !code.includes('require(')) return null
      let n = 0
      const heads = []
      const out = code.replace(
        /require\(\s*(['"])([^'"]+)\1\s*\)/g,
        (_m, q, spec) => {
          const name = `__require_${n++}`
          heads.push(`import ${name} from ${q}${spec}${q}`)
          return name
        }
      )
      return heads.length ? heads.join('\n') + '\n' + out : null
    }
  }
}

export default defineConfig({
  plugins: [cjsRequire(), vue()],
  // 兼容层二：上游 import 组件时不写扩展名（import Card from './Card'），
  // webpack 会自动补，但 Vite 默认的 extensions 列表里没有 .vue。
  resolve: {
    extensions: ['.mjs', '.js', '.mts', '.ts', '.jsx', '.tsx', '.json', '.vue']
  },
  // 兼容层三：上游 router.js 用了 Vue CLI 注入的 process.env.BASE_URL，
  // Vite 环境里没有 process，不补会 ReferenceError 导致整页空白。
  define: {
    'process.env.BASE_URL': JSON.stringify('/')
  },
  server: { host: '127.0.0.1', port: 5175, strictPort: true }
})