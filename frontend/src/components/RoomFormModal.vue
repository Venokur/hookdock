<script setup>
import { computed, reactive, ref } from 'vue'
import { useRoomsStore } from '../stores/rooms'
import AppInput from './ui/AppInput.vue'
import { uniqueSlug } from '../utils/slug'

const props = defineProps({
  room: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const roomsStore = useRoomsStore()

const form = reactive({
  name: props.room?.name ?? '',
})
const saving = ref(false)
const error = ref('')

const isEdit = computed(() => Boolean(props.room))

/**
 * The slug is assigned by the server from the room name and never edited here:
 * it is part of the webhook URL, so changing it would silently break the
 * integrations already pointing at it. `uniqueSlug` mirrors the server so the
 * disabled field previews what will be stored.
 */
const slugPreview = computed(() => {
  if (isEdit.value) {
    return props.room.slug
  }

  return form.name.trim()
    ? uniqueSlug(form.name, roomsStore.rooms.map((room) => room.slug))
    : ''
})

async function submit() {
  error.value = ''

  if (!form.name.trim()) {
    error.value = 'Укажите название комнаты.'
    return
  }

  saving.value = true

  try {
    // No slug in the payload: the server derives and de-duplicates it.
    const payload = { name: form.name.trim() }
    const saved = isEdit.value
      ? await roomsStore.update(props.room.id, payload)
      : await roomsStore.create(payload)

    emit('saved', saved)
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/30 p-4 backdrop-blur-sm dark:bg-slate-950/80 sm:p-8"
        @click.self="emit('close')"
      >
        <form class="card my-auto w-full max-w-md overflow-hidden" @submit.prevent="submit">
          <header class="flex items-start justify-between gap-4 border-b border-line px-6 py-5">
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">
                {{ isEdit ? 'Настройки комнаты' : 'Новая комната' }}
              </p>
              <h2 class="mt-1 text-lg font-semibold text-ink">
                {{ isEdit ? form.name || 'Комната' : 'Куда складывать запросы' }}
              </h2>
            </div>
            <button
              type="button"
              class="rounded-lg p-1.5 text-ink-mute transition hover:bg-surface-2 hover:text-ink"
              aria-label="Закрыть"
              @click="emit('close')"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
              </svg>
            </button>
          </header>

          <div class="space-y-4 px-6 py-6">
            <label class="block">
              <span class="mb-1.5 block text-xs font-medium text-ink-soft">Название</span>
              <AppInput v-model="form.name" placeholder="Стрип · прод" autofocus />
            </label>

            <label class="block">
              <span class="mb-1.5 block text-xs font-medium text-ink-soft">Slug в адресе</span>
              <AppInput
                :model-value="slugPreview"
                placeholder="присвоится автоматически"
                class="font-mono"
                disabled
              />
            </label>

            <p class="text-[11px] text-ink-mute">
              <template v-if="slugPreview">
                Вебхуки комнаты: /hook/{{ slugPreview }}/stripe. Slug присваивается автоматически и
                не меняется.
              </template>
              <template v-else> Slug присвоится автоматически из названия комнаты. </template>
            </p>

            <p
              v-if="error"
              class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-600 dark:text-rose-300"
            >
              {{ error }}
            </p>
          </div>

          <footer class="flex items-center justify-end gap-2 border-t border-line bg-surface-2 px-6 py-4">
            <button type="button" class="btn" @click="emit('close')">Отмена</button>
            <button type="submit" class="btn-primary" :disabled="saving">
              {{ saving ? 'Сохраняем…' : isEdit ? 'Сохранить' : 'Создать' }}
            </button>
          </footer>
        </form>
      </div>
    </Transition>
  </Teleport>
</template>
