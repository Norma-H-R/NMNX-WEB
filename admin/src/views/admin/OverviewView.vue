<script setup lang="ts">
import ModulePlaceholder from '@/components/admin/ModulePlaceholder.vue'

// 组件名必须和路由 name 完全一致 —— KeepAlive 的 include 就是按这个名字认缓存的
defineOptions({ name: 'admin-overview' })
</script>

<template>
  <ModulePlaceholder title="概览" />
</template>
