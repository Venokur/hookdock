<script setup>
import { computed, ref, watch } from 'vue'
import { flattenTree, typeLabel } from '../utils/jsonPaths'

defineOptions({ name: 'JsonTree' })

const CHUNK = 50

const props = defineProps({
  node: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  selected: { type: String, default: '' },
  filter: { type: String, default: '' },
})

const emit = defineEmits(['select'])

// Root and first level start open, the rest stays collapsed: webhook payloads
// are often 5+ levels deep and an all-expanded tree is unreadable.
const open = ref(props.depth < 2)
const limit = ref(CHUNK)

const query = computed(() => props.filter.trim().toLowerCase())

const selfMatches = computed(() =>
  query.value !== '' && props.node.path.toLowerCase().includes(query.value),
)

/** A branch stays visible while anything under it matches the search. */
const hasMatchingDescendant = computed(() => {
  if (!query.value) {
    return false
  }

  return flattenTree(props.node).some(
    (item) => item !== props.node && item.path.toLowerCase().includes(query.value),
  )
})

const visible = computed(() => query.value === '' || selfMatches.value || hasMatchingDescendant.value)

const isBlob = computed(() => props.node.type === 'object' || props.node.type === 'array')
const isSelected = computed(() => props.node.path === props.selected)

// Cheap prefix check instead of walking the subtree for every row.
const selectedInside = computed(
  () =>
    props.selected !== '' &&
    props.selected !== props.node.path &&
    (props.selected.startsWith(`${props.node.path}.`) || props.selected.startsWith(`${props.node.path}[`)),
)

const shownChildren = computed(() => {
  if (query.value !== '') {
    return props.node.children
  }

  return props.node.children.slice(0, limit.value)
})

const hiddenCount = computed(() => props.node.children.length - shownChildren.value.length)

const rowClass = computed(() => {
  if (isSelected.value) {
    return 'bg-indigo-50 text-indigo-700 dark:bg-white/10 dark:text-white'
  }

  return 'text-ink-soft hover:bg-surface-2 hover:text-ink'
})

// A filter must reveal the branches that lead to a match, otherwise the hit is
// invisible inside a collapsed parent.
watch(query, (value) => {
  if (value !== '' && hasMatchingDescendant.value) {
    open.value = true
  }
})

function toggle() {
  open.value = !open.value
}

function choose() {
  if (!props.node.selectable) {
    return
  }

  emit('select', props.node)
}
</script>

<template>
  <li v-if="visible">
    <div class="flex items-center gap-1 rounded-lg pr-1 transition" :class="rowClass">
      <button
        v-if="node.children.length"
        type="button"
        class="grid h-5 w-5 shrink-0 place-items-center rounded text-ink-mute transition hover:text-ink"
        :aria-label="open ? 'Свернуть' : 'Развернуть'"
        :aria-expanded="open"
        @click.stop="toggle"
      >
        <svg
          class="h-3 w-3 transition-transform duration-150"
          :class="{ 'rotate-90': open }"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2.4"
          aria-hidden="true"
        >
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
        </svg>
      </button>
      <span v-else class="h-5 w-5 shrink-0" aria-hidden="true"></span>

      <button
        type="button"
        class="flex min-w-0 flex-1 items-center gap-2 py-1 text-left"
        :class="{ 'cursor-default': !node.selectable }"
        :disabled="!node.selectable"
        :aria-pressed="isSelected"
        :title="node.selectable ? node.path : `${node.path} — ключ содержит точку, путь по нему не строится`"
        @click="choose"
      >
        <span
          class="truncate font-mono text-[11px]"
          :class="[isSelected ? 'font-semibold' : 'text-ink', node.selectable ? '' : 'opacity-60']"
        >
          {{ node.key }}
        </span>
        <span class="shrink-0 text-[10px] uppercase tracking-wide text-ink-mute">{{ typeLabel(node.type) }}</span>
        <span class="min-w-0 truncate font-mono text-[10px] text-ink-mute">{{ node.preview }}</span>

        <span
          v-if="selectedInside"
          class="shrink-0 rounded bg-indigo-500/10 px-1 text-[9px] font-medium text-indigo-600 dark:text-indigo-300"
        >внутри</span>
      </button>
    </div>

    <ul v-if="open && shownChildren.length" class="ml-3 border-l border-line pl-1">
      <JsonTree
        v-for="child in shownChildren"
        :key="child.path"
        :node="child"
        :depth="depth + 1"
        :selected="selected"
        :filter="filter"
        @select="emit('select', $event)"
      />

      <li v-if="hiddenCount > 0" class="py-1 pl-6">
        <button
          type="button"
          class="rounded-md px-1.5 py-0.5 text-[10px] font-medium text-indigo-600 transition hover:bg-indigo-500/10 dark:text-indigo-300"
          @click="limit += CHUNK"
        >
          Показать ещё {{ Math.min(hiddenCount, CHUNK) }} из {{ hiddenCount }}
        </button>
      </li>
    </ul>
  </li>
</template>
