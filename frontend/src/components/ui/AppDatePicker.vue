<script setup>
/**
 * Calendar + time, drawn by us.
 *
 * The native `datetime-local` control is rendered by the browser (and, in Edge,
 * partly by the OS), so it ignores the theme, its panel cannot be styled and it
 * shows a language/format the operator did not choose. This is the same widget,
 * built from the theme tokens: a `role="combobox"` trigger, a fixed-position
 * panel teleported to `<body>` (so a drawer or a modal cannot clip it) and a
 * 42-cell month grid that keeps its height between months.
 *
 * The model is the string the native input produced — `YYYY-MM-DD` for
 * `withTime: false`, `YYYY-MM-DDTHH:mm` otherwise — because that is what the API
 * and the saved filters already speak. Nothing here knows about timezones: a
 * typed «18:30» stays «18:30», the same way the native control behaved.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import {
  MONTHS_NOMINATIVE,
  WEEKDAYS_SHORT,
  dayNumber,
  daysInMonth,
  displayValue,
  isAfterDay,
  isBeforeDay,
  longDate,
  monthCells,
  monthLabel,
  nowParts,
  pad2,
  parseParts,
  toIsoDate,
  toIsoDateTime,
  todayParts,
  weekdayIndex,
} from '../../utils/date'

const model = defineModel({ type: String, default: '' })

const props = defineProps({
  /** `datetime-local` shape: the day plus an hour and a minute. */
  withTime: Boolean,
  size: { type: String, default: 'md' },
  placeholder: { type: String, default: '' },
  disabled: Boolean,
  invalid: Boolean,
  /** ISO day (`YYYY-MM-DD`) or full value; the calendar greys out the rest. */
  min: { type: String, default: '' },
  max: { type: String, default: '' },
  ariaLabel: { type: String, default: '' },
})

const sizes = {
  sm: 'rounded-lg px-2.5 py-1.5 text-xs',
  md: 'rounded-xl px-3 py-2 text-sm',
}

const triggerRef = ref(null)
const panelRef = ref(null)
const gridRef = ref(null)
const hourRef = ref(null)
const minuteRef = ref(null)
const cellRefs = ref({})
const open = ref(false)
const mode = ref('days')
const position = ref({ top: 0, left: 0, width: 280, placement: 'bottom' })

const view = ref({ year: 0, month: 0 })
const cursor = ref(null)

const minParts = computed(() => parseParts(props.min))
const maxParts = computed(() => parseParts(props.max))

const selected = computed(() => parseParts(model.value))

const valueText = computed(() => displayValue(selected.value, props.withTime))

const titleText = computed(() => longDate(selected.value, props.withTime))

const cells = computed(() => monthCells(view.value.year, view.value.month))

const triggerClasses = computed(() => [
  sizes[props.size] ?? sizes.md,
  props.invalid
    ? 'border-rose-500/60'
    : open.value
      ? 'border-indigo-500 ring-2 ring-indigo-500/15'
      : 'border-line',
])

const hours = computed(() => selected.value?.hour ?? 0)
const minutes = computed(() => selected.value?.minute ?? 0)

// The two time fields hold text while they are being edited (see `onTimeInput`).
const hourText = ref('00')
const minuteText = ref('00')

watch(
  () => [hours.value, minutes.value, open.value],
  () => {
    hourText.value = pad2(hours.value)
    minuteText.value = pad2(minutes.value)
  },
  { immediate: true },
)

function isDisabledDay(cell) {
  if (minParts.value && isBeforeDay(cell, minParts.value)) {
    return true
  }

  return Boolean(maxParts.value && isAfterDay(cell, maxParts.value))
}

function isToday(cell) {
  const today = todayParts()

  return dayNumber(cell) === dayNumber(today)
}

function isSelected(cell) {
  return Boolean(selected.value && dayNumber(cell) === dayNumber(selected.value))
}

function isCursor(cell) {
  return Boolean(cursor.value && dayNumber(cell) === dayNumber(cursor.value))
}

function toggle() {
  if (open.value) {
    close()
  } else {
    openPanel()
  }
}

function openPanel() {
  if (props.disabled) {
    return
  }

  const anchor = selected.value ?? clampToRange(todayParts())

  view.value = { year: anchor.year, month: anchor.month }
  cursor.value = { ...anchor }
  mode.value = 'days'
  open.value = true

  nextTick(() => {
    updatePosition()
    focusCursor()
  })
}

/** «Сегодня» is outside `min`/`max` often enough to be worth clamping. */
function clampToRange(parts) {
  if (minParts.value && isBeforeDay(parts, minParts.value)) {
    return minParts.value
  }

  if (maxParts.value && isAfterDay(parts, maxParts.value)) {
    return maxParts.value
  }

  return parts
}

function close() {
  open.value = false
  mode.value = 'days'
}

function emitParts(parts) {
  model.value = props.withTime ? toIsoDateTime(parts) : toIsoDate(parts)
}

function chooseDay(cell) {
  if (isDisabledDay(cell)) {
    return
  }

  const time = props.withTime
    ? { hour: selected.value?.hour ?? 0, minute: selected.value?.minute ?? 0 }
    : { hour: null, minute: null }

  emitParts({ year: cell.year, month: cell.month, day: cell.day, ...time })

  if (props.withTime) {
    // Picking the day is only half of the answer; the time field is right below.
    nextTick(() => hourRef.value?.focus())
  } else {
    close()
    nextTick(() => triggerRef.value?.focus())
  }
}

function shiftMonth(delta) {
  const date = new Date(view.value.year, view.value.month + delta, 1)

  view.value = { year: date.getFullYear(), month: date.getMonth() }
}

function shiftYear(delta) {
  view.value = { ...view.value, year: view.value.year + delta }
}

function goToday() {
  const today = clampToRange(props.withTime ? nowParts() : todayParts())

  view.value = { year: today.year, month: today.month }
  cursor.value = { ...today }
  mode.value = 'days'
  emitParts(today)
}

function clear() {
  model.value = ''
  close()
  nextTick(() => triggerRef.value?.focus())
}

function onTimeInput(part, event) {
  // Kept local while typing: writing through on every keystroke would re-render
  // the field as «05» after the first digit and move the caret, so «18:30» was
  // impossible to type. The value is committed on blur or Enter.
  if (part === 'hour') {
    hourText.value = event.target.value
  } else {
    minuteText.value = event.target.value
  }
}

function commitTime(part) {
  const raw = String(part === 'hour' ? hourText.value : minuteText.value).replace(/\D/g, '')

  if (raw === '') {
    syncTimeText()
    return
  }

  // Each field parses its own value: «45» in the minutes box means 45 minutes,
  // while a shared `HHmm` parser would read it as 45 hours and clamp it to 23.
  const value = part === 'hour'
    ? Math.min(23, Number(raw))
    : Math.min(59, Number(raw.slice(0, 2)))

  const day = selected.value ?? todayParts()

  emitParts({
    year: day.year,
    month: day.month,
    day: day.day,
    hour: part === 'hour' ? value : hours.value,
    minute: part === 'minute' ? value : minutes.value,
  })

  syncTimeText()
}

function syncTimeText() {
  hourText.value = pad2(hours.value)
  minuteText.value = pad2(minutes.value)
}

function focusCell(cell) {
  const key = `${cell.year}-${cell.month}-${cell.day}`
  cellRefs.value[key]?.focus()
}

function focusCursor() {
  if (cursor.value) {
    focusCell(cursor.value)
  }
}

/**
 * Arrow keys move the cursor even across a month boundary, so a keyboard user
 * never has to open the month switcher to reach «завтра».
 */
function onGridKeydown(event) {
  if (mode.value !== 'days' || !cursor.value) {
    return
  }

  const step = {
    ArrowLeft: [-0, -1],
    ArrowRight: [0, 1],
    ArrowUp: [-1, 0],
    ArrowDown: [1, 0],
  }[event.key]

  if (step) {
    event.preventDefault()
    moveCursor(step[0] * 7 + step[1])
    return
  }

  switch (event.key) {
    case 'PageUp':
      event.preventDefault()
      moveCursor(-daysInMonth(view.value.year, view.value.month))
      break
    case 'PageDown':
      event.preventDefault()
      moveCursor(daysInMonth(view.value.year, view.value.month))
      break
    case 'Home':
      event.preventDefault()
      moveCursor(-weekdayIndex(cursor.value.year, cursor.value.month, cursor.value.day))
      break
    case 'End':
      event.preventDefault()
      moveCursor(6 - weekdayIndex(cursor.value.year, cursor.value.month, cursor.value.day))
      break
    case 'Enter':
    case ' ':
      event.preventDefault()
      chooseDay(cursor.value)
      break
    case 'Escape':
      event.preventDefault()
      close()
      nextTick(() => triggerRef.value?.focus())
      break
    default:
      break
  }
}

function moveCursor(days) {
  const base = new Date(cursor.value.year, cursor.value.month, cursor.value.day + days)
  const next = { year: base.getFullYear(), month: base.getMonth(), day: base.getDate() }

  cursor.value = next

  if (next.year !== view.value.year || next.month !== view.value.month) {
    view.value = { year: next.year, month: next.month }
  }

  nextTick(() => focusCell(next))
}

function updatePosition() {
  const trigger = triggerRef.value

  if (!trigger) {
    return
  }

  const rect = trigger.getBoundingClientRect()
  const gap = 6
  const width = Math.max(rect.width, 288)
  const height = panelRef.value?.offsetHeight ?? 360
  const spaceBelow = window.innerHeight - rect.bottom - gap - 8
  const spaceAbove = rect.top - gap - 8
  const placement = spaceBelow < height && spaceAbove > spaceBelow ? 'top' : 'bottom'
  const left = Math.min(Math.max(8, rect.left), Math.max(8, window.innerWidth - width - 8))

  position.value = {
    left,
    width,
    top: placement === 'bottom' ? rect.bottom + gap : rect.top - gap,
    placement,
  }

  // The first measurement happens before the panel has content, so the height
  // used for the flip decision is an estimate; one pass after mount corrects it.
  if (panelRef.value && Math.abs((panelRef.value.offsetHeight ?? 0) - height) > 1) {
    updatePosition()
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
  if (isOpen) {
    document.addEventListener('pointerdown', onPointerDown, true)
    window.addEventListener('scroll', updatePosition, true)
    window.addEventListener('resize', updatePosition)
  } else {
    document.removeEventListener('pointerdown', onPointerDown, true)
    window.removeEventListener('scroll', updatePosition, true)
    window.removeEventListener('resize', updatePosition)
  }
})

watch(mode, (next) => {
  if (next === 'days' && open.value) {
    nextTick(() => {
      updatePosition()
      focusCursor()
    })
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onPointerDown, true)
  window.removeEventListener('scroll', updatePosition, true)
  window.removeEventListener('resize', updatePosition)
})
</script>

<template>
  <div class="relative">
    <button
      ref="triggerRef"
      type="button"
      role="combobox"
      aria-haspopup="dialog"
      :aria-expanded="open"
      :aria-label="props.ariaLabel || undefined"
      :disabled="props.disabled"
      :title="titleText || undefined"
      class="flex w-full cursor-pointer items-center justify-between gap-2 border bg-surface text-left text-ink outline-none transition
        disabled:cursor-not-allowed disabled:opacity-60"
      :class="triggerClasses"
      @click="toggle"
      @keydown.down.prevent="openPanel"
      @keydown.enter.prevent="toggle"
      @keydown.space.prevent="toggle"
      @keydown.esc="close"
    >
      <span class="truncate" :class="valueText ? 'text-ink' : 'text-ink-mute'">
        {{ valueText || props.placeholder || (props.withTime ? 'Дата и время' : 'Дата') }}
      </span>

      <span class="grid h-3.5 w-3.5 shrink-0 place-items-center text-ink-mute">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
          <rect x="3" y="5" width="18" height="16" rx="3" />
          <path stroke-linecap="round" d="M8 3v4m8-4v4M3 10h18" />
        </svg>
      </span>
    </button>

    <Teleport to="body">
      <div
        v-if="open"
        ref="panelRef"
        role="dialog"
        :aria-label="props.ariaLabel || 'Выбор даты'"
        :style="{
          left: position.left + 'px',
          top: position.top + 'px',
          width: position.width + 'px',
          transform: position.placement === 'top' ? 'translateY(-100%)' : 'none',
        }"
        class="fixed z-[70] overflow-hidden rounded-xl border border-line bg-surface p-2.5 shadow-xl shadow-slate-900/10 dark:shadow-black/40"
      >
        <div class="flex items-center justify-between gap-1 pb-2">
          <button
            type="button"
            class="grid h-7 w-7 place-items-center rounded-lg text-ink-mute transition hover:bg-surface-2 hover:text-ink"
            :aria-label="mode === 'days' ? 'Предыдущий месяц' : 'Предыдущий год'"
            @click="mode === 'days' ? shiftMonth(-1) : shiftYear(-1)"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 6l-6 6 6 6" />
            </svg>
          </button>

          <button
            type="button"
            class="rounded-lg px-2 py-1 text-xs font-semibold text-ink transition hover:bg-surface-2"
            :aria-label="mode === 'days' ? 'Выбрать месяц и год' : 'Вернуться к дням'"
            @click="mode = mode === 'days' ? 'months' : 'days'"
          >
            {{ mode === 'days' ? monthLabel(view.year, view.month) : view.year }}
          </button>

          <button
            type="button"
            class="grid h-7 w-7 place-items-center rounded-lg text-ink-mute transition hover:bg-surface-2 hover:text-ink"
            :aria-label="mode === 'days' ? 'Следующий месяц' : 'Следующий год'"
            @click="mode === 'days' ? shiftMonth(1) : shiftYear(1)"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
            </svg>
          </button>
        </div>

        <div v-if="mode === 'days'" ref="gridRef" @keydown="onGridKeydown">
          <div class="grid grid-cols-7 pb-1">
            <span
              v-for="weekday in WEEKDAYS_SHORT"
              :key="weekday"
              class="grid h-6 place-items-center text-[10px] font-medium uppercase tracking-wide text-ink-mute"
            >
              {{ weekday }}
            </span>
          </div>

          <div class="grid grid-cols-7 gap-0.5">
            <button
              v-for="cell in cells"
              :key="`${cell.year}-${cell.month}-${cell.day}`"
              :ref="(node) => (cellRefs[`${cell.year}-${cell.month}-${cell.day}`] = node)"
              type="button"
              role="gridcell"
              :aria-selected="isSelected(cell)"
              :aria-disabled="isDisabledDay(cell) || undefined"
              :aria-label="longDate(cell)"
              :disabled="isDisabledDay(cell)"
              :tabindex="isCursor(cell) ? 0 : -1"
              class="relative grid h-8 place-items-center rounded-lg text-xs transition
                disabled:cursor-not-allowed disabled:opacity-30"
              :class="[
                isSelected(cell)
                  ? 'bg-indigo-600 font-semibold text-white hover:bg-indigo-500 dark:bg-indigo-500 dark:hover:bg-indigo-400'
                  : cell.inMonth
                    ? 'text-ink hover:bg-surface-2'
                    : 'text-ink-mute hover:bg-surface-2',
                !isSelected(cell) && isToday(cell) ? 'ring-1 ring-inset ring-indigo-500/50' : '',
              ]"
              @click="chooseDay(cell)"
              @mouseenter="cursor = { ...cell }"
            >
              {{ cell.day }}
            </button>
          </div>
        </div>

        <div v-else class="grid grid-cols-3 gap-1">
          <button
            v-for="(month, index) in MONTHS_NOMINATIVE"
            :key="month"
            type="button"
            class="rounded-lg px-2 py-2 text-xs transition"
            :class="index === view.month && view.year === (selected?.year ?? view.year)
              ? 'bg-indigo-600 font-semibold text-white hover:bg-indigo-500 dark:bg-indigo-500 dark:hover:bg-indigo-400'
              : 'text-ink hover:bg-surface-2'"
            @click="view = { ...view, month: index }, (mode = 'days')"
          >
            {{ month.slice(0, 3) }}
          </button>
        </div>

        <!-- Time is two fields, not a spinner: «18» and «30» are two characters
             each, and a native `type="time"` would bring back the OS widget this
             component exists to replace. -->
        <div v-if="props.withTime && mode === 'days'" class="mt-2 flex items-center gap-1.5 border-t border-line pt-2">
          <span class="text-[11px] text-ink-mute">Время</span>
          <input
            ref="hourRef"
            v-model="hourText"
            type="text"
            inputmode="numeric"
            maxlength="2"
            aria-label="Часы"
            class="w-9 rounded-lg border border-line bg-surface px-1 py-1 text-center font-mono text-xs text-ink outline-none transition
              focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/15"
            @input="onTimeInput('hour', $event)"
            @blur="commitTime('hour')"
            @keydown.enter.prevent="commitTime('hour')"
          />
          <span class="text-xs text-ink-mute">:</span>
          <input
            ref="minuteRef"
            v-model="minuteText"
            type="text"
            inputmode="numeric"
            maxlength="2"
            aria-label="Минуты"
            class="w-9 rounded-lg border border-line bg-surface px-1 py-1 text-center font-mono text-xs text-ink outline-none transition
              focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/15"
            @input="onTimeInput('minute', $event)"
            @blur="commitTime('minute')"
            @keydown.enter.prevent="commitTime('minute')"
          />
          <button type="button" class="ml-auto text-[11px] font-medium text-indigo-600 transition hover:underline dark:text-indigo-300" @click="goToday">
            Сейчас
          </button>
        </div>

        <div class="mt-2 flex items-center justify-between gap-2 border-t border-line pt-2">
          <button
            type="button"
            class="rounded-lg px-2 py-1 text-[11px] font-medium text-ink-mute transition hover:bg-surface-2 hover:text-ink disabled:invisible"
            :disabled="!model"
            @click="clear"
          >
            Очистить
          </button>
          <div class="flex items-center gap-2">
            <button
              v-if="!props.withTime"
              type="button"
              class="rounded-lg px-2 py-1 text-[11px] font-medium text-indigo-600 transition hover:bg-indigo-500/10 dark:text-indigo-300"
              @click="goToday"
            >
              Сегодня
            </button>
            <button
              type="button"
              class="rounded-lg bg-indigo-600 px-2.5 py-1 text-[11px] font-semibold text-white transition hover:bg-indigo-500 dark:bg-indigo-500 dark:hover:bg-indigo-400"
              @click="close(), nextTick(() => triggerRef?.focus())"
            >
              Готово
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
