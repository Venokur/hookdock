<script setup>
import { onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()
const router = useRouter()

function handleExpired() {
  auth.logout()
  router.push({ name: 'login' })
}

onMounted(() => {
  auth.fetchMe()
  window.addEventListener('auth:expired', handleExpired)
})

onUnmounted(() => {
  window.removeEventListener('auth:expired', handleExpired)
})
</script>

<template>
  <router-view />
</template>
