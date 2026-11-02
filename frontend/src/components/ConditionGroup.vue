<script setup>
import AppInput from './ui/AppInput.vue'
import AppSelect from './ui/AppSelect.vue'

/**
 * One node of a condition tree: a group of rules joined by AND or OR.
 *
 * Recursive on purpose. The backend stores `{mode, rules}`, and a flat list cannot
 * express "source = stripe AND (type = paid OR type = refunded)" — the shape most
 * real routing rules actually have. Rendering the same component for a nested
 * group keeps the editor and the stored tree identical, so the JSON that is saved
 * is exactly what the operator sees.
 */
const props = defineProps({
  group: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  operators: { type: Array, required: true },
  maxDepth: { type: Number, default: 5 },
})

const emit = defineEmits(['pick'])

const modeOptions = [
  { value: 'all', label: 'Все условия (И)' },
  { value: 'any', label: 'Любое условие (ИЛИ)' },
]

// `exists` / `not_exists` ask only for a path: a value field there is noise that
// the operator fills in and the engine ignores.
function needsValue(operator) {
  return !['exists', 'not_exists'].includes(operator)
}

function isGroup(rule) {
  return Array.isArray(rule.rules)
}

function addRule() {
  props.group.rules.push({ path: '', operator: 'equals', value: '' })
}

function addGroup() {
  props.group.rules.push({ mode: 'all', rules: [] })
}

function remove(index) {
  props.group.rules.splice(index, 1)
}
</script>

<template>
  <div
    class="rounded-xl border border-line bg-surface-2 p-3"
    :class="depth > 0 ? 'border-l-2 border-l-indigo-500/40' : ''"
  >
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div class="flex items-center gap-2">
        <AppSelect v-model="group.mode" :options="modeOptions" size="sm" class="w-44" />
        <span class="text-[11px] text-ink-mute">
          {{ group.mode === 'any' ? 'достаточно одного правила' : 'должны совпасть все правила' }}
        </span>
      </div>

      <div class="flex items-center gap-1">
        <button type="button" class="btn px-2 py-1 text-[11px]" @click="addRule">+ Условие</button>
        <button
          v-if="depth + 1 < maxDepth"
          type="button"
          class="btn px-2 py-1 text-[11px]"
          @click="addGroup"
        >
          + Группа
        </button>
      </div>
    </div>

    <div class="mt-3 space-y-2">
      <p
        v-if="group.rules.length === 0"
        class="rounded-lg border border-dashed border-line px-3 py-2 text-[11px] text-ink-mute"
      >
        {{ depth === 0 ? 'Правил нет — сценарий сработает на каждый запрос.' : 'Пустая группа пропускает всё.' }}
      </p>

      <template v-for="(rule, index) in group.rules" :key="index">
        <ConditionGroup
          v-if="isGroup(rule)"
          :group="rule"
          :depth="depth + 1"
          :operators="operators"
          :max-depth="maxDepth"
          @pick="(target) => emit('pick', target)"
        >
        </ConditionGroup>

        <div
          v-else
          class="grid grid-cols-1 gap-2 rounded-lg border border-line bg-surface p-2 sm:grid-cols-[1.4fr_1fr_1fr_auto]"
        >
          <div class="flex items-center gap-1">
            <AppInput
              v-model="rule.path"
              size="sm"
              placeholder="body.event.type"
              aria-label="Путь к полю"
              class="font-mono"
            />
            <button
              type="button"
              class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border border-line bg-surface font-mono text-[11px] text-ink-soft transition hover:bg-surface-2 hover:text-ink"
              aria-label="Выбрать путь из запроса"
              title="Выбрать путь из запроса"
              @click="emit('pick', rule)"
            >
              { }
            </button>
          </div>
          <AppSelect v-model="rule.operator" :options="operators" size="sm" />
          <AppInput v-if="needsValue(rule.operator)" v-model="rule.value" size="sm" placeholder="значение" />
          <span v-else class="hidden sm:block"></span>
          <button
            type="button"
            class="justify-self-start rounded-lg p-1.5 text-ink-mute transition hover:bg-rose-500/10 hover:text-rose-600 dark:hover:text-rose-300 sm:justify-self-end"
            aria-label="Удалить условие"
            @click="remove(index)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" d="M6 7h12M9 7V5h6v2m-8 0l1 13h8l1-13" />
            </svg>
          </button>
        </div>

        <div class="flex justify-end" v-if="isGroup(rule)">
          <button
            type="button"
            class="rounded-lg px-2 py-1 text-[11px] text-ink-mute transition hover:bg-rose-500/10 hover:text-rose-600 dark:hover:text-rose-300"
            @click="remove(index)"
          >
            Удалить группу
          </button>
        </div>
      </template>
    </div>
  </div>
</template>
