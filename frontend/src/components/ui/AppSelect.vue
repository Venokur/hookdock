<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import AppSpinner from './AppSpinner.vue'

const model = defineModel({ type: [String, Number], default: '' })

const props = defineProps({
  options: { type: Array, default: () => [] },
  size: { type: String, default: 'md' },
  placeholder: { type: String, default: 'Выберите значение' },
  disabled: Boolean,
  invalid: Boolean,
  /** Swaps the chevron for a spinner while the value is being applied. */
  loading: Boolean,
  /** Shows a spinner inside the option panel while its contents are being fetched. */
  searching: Boolean,
  /**
   * Adds a text field inside the panel. The component only owns the field and the
   * debounce; the caller decides what to do with the term (`@search`), because
   * the list may have to be fetched from the server — a room can have hundreds
   * of endpoints, and a filter that can only pick from the visible page is a
   * filter that cannot reach the rest.
   */
  searchable: Boolean,
  searchPlaceholder: { type: String, default: 'Поиск…' },
  /** One line under the list: "показаны первые 15 из 40" and similar. */
  hint: { type: String, default: '' },
  ariaLabel: { type: String, default: '' },
})

const emit = defineEmits(['search'])

const uid = useId()
const listId = `listbox-${uid}`
const triggerRef = ref(null)
const listRef = ref(null)
const panelRef = ref(null)
const searchRef = ref(null)
const open = ref(false)
const activeIndex = ref(-1)
const query = ref('')
const position = ref({ top: 0, left: 0, width: 0, maxHeight: 288, placement: 'bottom' })

const SEARCH_DEBOUNCE = 250
let searchQueryTimer = null

const sizes = {
  sm: 'rounded-lg px-2.5 py-1.5 text-xs',
  md: 'rounded-xl px-3 py-2 text-sm',
}

const selectedIndex = computed(() =>
  props.options.findIndex((option) => String(option.value) === String(model.value)),
)

const selected = computed(() => (selectedIndex.value >= 0 ? props.options[selectedIndex.value] : null))

const triggerClasses = computed(() => [
  sizes[props.size] ?? sizes.md,
  props.invalid
    ? 'border-rose-500/60'
    : open.value
      ? 'border-indigo-500 ring-2 ring-indigo-500/15'
      : 'border-line',
])

const activeDescendant = computed(() =>
  open.value && activeIndex.value >= 0 ? `${listId}-option-${activeIndex.value}` : undefined,
)

function toggle() {
  if (open.value) {
    close()
  } else {
    openMenu()
  }
}

function openMenu() {
  if (props.disabled || props.options.length === 0) {
    return
  }

  open.value = true
  activeIndex.value = selectedIndex.value >= 0 ? selectedIndex.value : firstEnabled()

  nextTick(() => {
    updatePosition()
    scrollActiveIntoView()
    if (props.searchable) {
      searchRef.value?.focus()
    }
  })
}

function close() {
  open.value = false
  activeIndex.value = -1
  clearTimeout(searchQueryTimer)
  // A term left over from the previous open would filter the next one before
  // the user typed anything.
  query.value = ''
  emit('search', '')
}

function choose(option) {
  if (!option || option.disabled) {
    return
  }

  model.value = option.value
  close()
  nextTick(() => triggerRef.value?.focus())
}

/**
 * Debounced so a keystroke does not start a request per letter, while Enter in
 * the field (or the caller's own magnifier) can ask for the term immediately.
 */
function onSearchInput(value) {
  query.value = value
  clearTimeout(searchQueryTimer)
  searchQueryTimer = setTimeout(() => emit('search', value.trim()), SEARCH_DEBOUNCE)
}

function searchNow() {
  clearTimeout(searchQueryTimer)
  emit('search', query.value.trim())
}

/** Escape inside the panel: same as Escape on the trigger. */
function closeAndFocus() {
  close()
  nextTick(() => triggerRef.value?.focus())
}

function firstEnabled() {
  return props.options.findIndex((option) => !option.disabled)
}

function lastEnabled() {
  for (let index = props.options.length - 1; index >= 0; index -= 1) {
    if (!props.options[index].disabled) {
      return index
    }
  }

  return -1
}

function move(delta) {
  const count = props.options.length
  if (count === 0) {
    return
  }

  let index = activeIndex.value

  for (let step = 0; step < count; step += 1) {
    index = (index + delta + count) % count
    if (!props.options[index].disabled) {
      activeIndex.value = index
      scrollActiveIntoView()
      return
    }
  }
}

function scrollActiveIntoView() {
  nextTick(() => {
    const node = listRef.value?.children[activeIndex.value]
    node?.scrollIntoView({ block: 'nearest' })
  })
}

let searchBuffer = ''
let searchTimer = null

function typeahead(key) {
  searchBuffer += key.toLowerCase()
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => (searchBuffer = ''), 600)

  const count = props.options.length
  const start = Math.max(activeIndex.value, 0)

  for (let offset = 1; offset <= count; offset += 1) {
    const index = (start + offset) % count
    const option = props.options[index]

    if (!option.disabled && String(option.label).toLowerCase().startsWith(searchBuffer)) {
      activeIndex.value = index
      scrollActiveIntoView()
      return
    }
  }
}

function onKeydown(event) {
  if (props.disabled) {
    return
  }

  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      if (open.value) {
        move(1)
      } else {
        openMenu()
      }
      break
    case 'ArrowUp':
      event.preventDefault()
      if (open.value) {
        move(-1)
      } else {
        openMenu()
        activeIndex.value = lastEnabled()
        scrollActiveIntoView()
      }
      break
    case 'Home':
      if (open.value) {
        event.preventDefault()
        activeIndex.value = firstEnabled()
        scrollActiveIntoView()
      }
      break
    case 'End':
      if (open.value) {
        event.preventDefault()
        activeIndex.value = lastEnabled()
        scrollActiveIntoView()
      }
      break
    case 'Enter':
    case ' ':
      event.preventDefault()
      if (open.value) {
        choose(props.options[activeIndex.value])
      } else {
        openMenu()
      }
      break
    case 'Escape':
      if (open.value) {
        event.preventDefault()
        close()
        triggerRef.value?.focus()
      }
      break
    case 'Tab':
      close()
      break
    default:
      // With a search field the keystrokes belong to it, not to a typeahead
      // buffer — otherwise the first letter would jump the active row while the
      // user is typing a term.
      if (open.value && !props.searchable && event.key.length === 1) {
        typeahead(event.key)
      }
  }
}

function updatePosition() {
  const trigger = triggerRef.value
  if (!trigger) {
    return
  }

  const rect = trigger.getBoundingClientRect()
  const gap = 6
  const itemHeight = 32
  // The search field (with its padding) and the hint line are part of the panel,
  // so a panel sized only from the row count would overflow off-screen.
  const extra = (props.searchable ? 44 : 0) + (props.hint ? 24 : 0)
  const desiredHeight = Math.min(288, props.options.length * itemHeight + 8 + extra)
  const spaceBelow = window.innerHeight - rect.bottom - gap - 8
  const spaceAbove = rect.top - gap - 8
  const placement = spaceBelow < desiredHeight && spaceAbove > spaceBelow ? 'top' : 'bottom'
  const width = Math.max(rect.width, 160)
  const left = Math.min(Math.max(8, rect.left), Math.max(8, window.innerWidth - width - 8))

  position.value = {
    left,
    width,
    top: placement === 'bottom' ? rect.bottom + gap : rect.top - gap,
    maxHeight: Math.max(96, Math.min(desiredHeight, placement === 'bottom' ? spaceBelow : spaceAbove)),
    placement,
  }
}

function onPointerDown(event) {
  const target = event.target

  if (triggerRef.value?.contains(target) || panelRef.value?.contains(target)) {
    return
  }

  close()
}

watch(open, (isOpen) => {
  checkOpenListeners(isOpen)
})

function checkOpenListeners(isOpen) {
  if (isOpen) {
    document.addEventListener('pointerdown', onPointerDown, true)
    window.addEventListener('scroll', updatePosition, true)
    window.addEventListener('resize', updatePosition)
  } else {
    document.removeEventListener('pointerdown', onPointerDown, true)
    window.removeEventListener('scroll', updatePosition, true)
    window.removeEventListener('resize', updatePosition)
  }
}

onBeforeUnmount(() => {
  checkOpenListeners(false)
  clearTimeout(searchTimer)
  clearTimeout(searchQueryTimer)
})
</script>

<template>
  <div class="relative">
    <button
      ref="triggerRef"
      type="button"
      role="combobox"
      aria-haspopup="listbox"
      :aria-expanded="open"
      :aria-controls="listId"
      :aria-activedescendant="activeDescendant"
      :aria-label="props.ariaLabel || undefined"
      :aria-busy="props.loading || undefined"
      :disabled="props.disabled"
      class="flex w-full cursor-pointer items-center justify-between gap-2 border bg-surface text-left text-ink outline-none transition
        disabled:cursor-not-allowed disabled:opacity-60"
      :class="triggerClasses"
      @click="toggle"
      @keydown="onKeydown"
    >
      <span class="truncate" :class="selected ? 'text-ink' : 'text-ink-mute'">
        {{ selected ? selected.label : props.placeholder }}
      </span>

      <span class="grid h-3.5 w-3.5 shrink-0 place-items-center text-ink-mute">
        <AppSpinner v-if="props.loading" size="xs" />
        <svg
          v-else
          class="h-3.5 w-3.5 transition-transform duration-150"
          :class="{ 'rotate-180': open }"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          aria-hidden="true"
        >
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" />
        </svg>
      </span>
    </button>

    <Teleport to="body">
      <div
        v-if="open"
        ref="panelRef"
        :style="{
          left: position.left + 'px',
          top: position.top + 'px',
          width: position.width + 'px',
          transform: position.placement === 'top' ? 'translateY(-100%)' : 'none',
        }"
        class="fixed z-[70] flex flex-col overflow-hidden rounded-xl border border-line bg-surface p-1 shadow-xl shadow-slate-900/10 dark:shadow-black/40"
      >
        <!-- The search field lives inside the panel: a second control next to the
             trigger would look like a different form element, and a native
             `<datalist>` draws an OS list that ignores the theme. -->
        <div v-if="props.searchable" class="relative p-1 pb-1.5">
          <input
            ref="searchRef"
            :value="query"
            type="text"
            role="searchbox"
            :placeholder="props.searchPlaceholder"
            aria-label="Поиск по значениям"
            autocomplete="off"
            spellcheck="false"
            class="w-full rounded-lg border border-line bg-surface px-2.5 py-1.5 pr-7 text-xs text-ink outline-none transition
              placeholder:text-ink-mute focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/15"
            @input="onSearchInput($event.target.value)"
            @keydown.enter.prevent="searchNow"
            @keydown.esc.prevent="closeAndFocus"
            @keydown.stop
          />
          <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-ink-mute">
            <AppSpinner v-if="props.searching" size="xs" />
            <svg
              v-else
              class="h-3 w-3"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
            >
              <circle cx="11" cy="11" r="7" />
              <path stroke-linecap="round" d="M20 20l-3.5-3.5" />
            </svg>
          </span>
        </div>

        <ul
          :id="listId"
          ref="listRef"
          role="listbox"
          :aria-label="props.ariaLabel || undefined"
          class="overflow-y-auto"
          :style="{ maxHeight: position.maxHeight - (props.searchable ? 44 : 0) - (props.hint ? 24 : 0) + 'px' }"
        >
          <li
            v-for="(option, index) in props.options"
            :id="`${listId}-option-${index}`"
            :key="option.value"
            role="option"
            :aria-selected="String(option.value) === String(model)"
            class="flex cursor-pointer items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-xs transition"
            :class="[
              index === activeIndex
                ? 'bg-indigo-50 text-indigo-700 dark:bg-white/10 dark:text-white'
                : 'text-ink-soft',
              option.disabled ? 'pointer-events-none opacity-40' : '',
            ]"
            @click="choose(option)"
            @mouseenter="activeIndex = index"
          >
            <span class="truncate">{{ option.label }}</span>

            <svg
              v-if="String(option.value) === String(model)"
              class="h-3.5 w-3.5 shrink-0 text-indigo-600 dark:text-indigo-300"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2.4"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <path d="M5 12.5l4.5 4.5L19 7.5" />
            </svg>
          </li>

          <!-- An empty search result is not "no data": the panel has to say which
               one it is, or the operator reads a broken filter. -->
          <li
            v-if="props.options.length === 0"
            class="px-2.5 py-2 text-[11px] text-ink-mute"
          >
            {{ props.searchable && query.trim() !== '' ? 'Ничего не найдено.' : 'Нет вариантов.' }}
          </li>
        </ul>

        <p v-if="props.hint" class="px-2.5 pb-1 pt-1 text-[10px] leading-snug text-ink-mute">
          {{ props.hint }}
        </p>
      </div>
    </Teleport>
  </div>
</template>
