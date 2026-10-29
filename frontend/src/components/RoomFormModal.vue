<script setup>
import { computed, reactive, ref } from 'vue'
import { useRoomsStore } from '../stores/rooms'
import AppInput from './ui/AppInput.vue'
import { randomEndpoint, isValidEndpoint } from '../utils/endpoint'
import { randomSlug, isValidSlug } from '../utils/slug'

const props = defineProps({
  room: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const roomsStore = useRoomsStore()

const form = reactive({
  name: props.room?.name ?? '',
  slug: props.room?.slug ?? randomSlug(),
  endpoint: props.room?.endpoint ?? randomEndpoint(),
})
const saving = ref(false)
const error = ref('')

const isEdit = computed(() => Boolean(props.room))

/**
 * The slug is part of the webhook URL and is random, not derived from the room
 * name, so a rename cannot silently break the integrations already pointing at
 * it. A new room sends the generated value, which lets the form preview the
 * address; an edit leaves it out of the payload and the server keeps it.
 */
const slugPreview = computed(() => {
  if (isEdit.value) {
    return props.room.slug
  }

  return form.slug.trim().toLowerCase()
})

/**
 * The endpoint is part of the address users copy into third-party services, so
 * it is shown as a full URL rather than an isolated slug. It is generated here
 * for a new room and can be typed over or regenerated before saving.
 */
const webhookPreview = computed(() => {
  const slug = slugPreview.value
  const endpoint = form.endpoint.trim()

  return slug && endpoint ? `/api/webhook/${slug}/${endpoint}` : ''
})

function regenerateSlug() {
  form.slug = randomSlug()
}

function regenerateEndpoint() {
  form.endpoint = randomEndpoint()
}

async function submit() {
  error.value = ''

  if (!form.name.trim()) {
    error.value = 'Укажите название комнаты.'
    return
  }

  const slug = form.slug.trim().toLowerCase()
  const endpoint = form.endpoint.trim().toLowerCase()

  // A new room sends its generated slug so the address in the form is the one
  // that gets stored. An empty slug lets the server draw a random one.
  if (!isEdit.value && slug !== '' && !isValidSlug(slug)) {
    error.value = 'Slug: латинские буквы, цифры и дефис, до 64 символов.'
    return
  }

  // An empty field is not an error: the server generates a random endpoint, and
  // an explicitly empty value asks it to replace the current one.
  if (endpoint !== '' && !isValidEndpoint(endpoint)) {
    error.value = 'Эндпоинт: латинские буквы, цифры и дефис, до 64 символов.'
    return
  }

  saving.value = true

  try {
    // The slug is sent only for a new room: an edit must not silently change an
    // address integrations already use, so the server keeps the current one.
    const payload = { name: form.name.trim(), endpoint }
    if (!isEdit.value) {
      payload.slug = slug
    }

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

            <div class="block">
              <span class="mb-1.5 block text-xs font-medium text-ink-soft">Slug в адресе</span>
              <AppInput
                v-if="isEdit"
                :model-value="slugPreview"
                class="font-mono"
                disabled
              />
              <div v-else class="flex items-center gap-2">
                <AppInput
                  v-model="form.slug"
                  placeholder="785e9e9c1b10-9a7be406d763"
                  aria-label="Slug в адресе"
                  class="font-mono"
                />
                <button
                  type="button"
                  class="btn shrink-0 px-3"
                  title="Сгенерировать случайный slug"
                  @click="regenerateSlug"
                >
                  Сгенерировать
                </button>
              </div>
            </div>

            <div class="block">
              <span class="mb-1.5 block text-xs font-medium text-ink-soft">Эндпоинт в адресе</span>
              <div class="flex items-center gap-2">
                <AppInput
                  v-model="form.endpoint"
                  placeholder="hook-7f3a9c"
                  aria-label="Эндпоинт в адресе"
                  class="font-mono"
                />
                <button
                  type="button"
                  class="btn shrink-0 px-3"
                  title="Сгенерировать случайный эндпоинт"
                  @click="regenerateEndpoint"
                >
                  Сгенерировать
                </button>
              </div>
            </div>

            <p class="text-[11px] text-ink-mute">
              <template v-if="isEdit">
                Адрес комнаты: <code class="font-mono">{{ webhookPreview }}</code>.
                Slug меняется только через API, чтобы не сломать настроенные интеграции; эндпоинт
                можно изменить здесь.
              </template>
              <template v-else-if="webhookPreview">
                Адрес комнаты: <code class="font-mono">{{ webhookPreview }}</code>.
                Пустые slug или эндпоинт сервер заменит случайными.
              </template>
              <template v-else>
                Slug и эндпоинт присвоятся автоматически, если оставить поля пустыми.
              </template>
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
