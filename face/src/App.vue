<script setup>
import StarField from './components/StarField.vue'
import SiteHeader from './components/SiteHeader.vue'
import HeroSection from './components/HeroSection.vue'
import AboutSection from './components/AboutSection.vue'
import CapabilitySection from './components/CapabilitySection.vue'
import ContactSection from './components/ContactSection.vue'
import SiteFooter from './components/SiteFooter.vue'
</script>

<template>
  <!-- 背景层：极光光晕 + 星场，都在内容之下 -->
  <div class="aurora" aria-hidden="true" />
  <StarField />

  <SiteHeader />

  <main>
    <HeroSection />
    <AboutSection />
    <CapabilitySection />
    <ContactSection />
  </main>

  <SiteFooter />

  <!-- 最上层质感噪点 -->
  <div class="grain" aria-hidden="true" />
</template>

<style scoped>
main {
  position: relative;
  z-index: 1;
}
</style>
