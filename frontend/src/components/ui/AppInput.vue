<script setup>
const model = defineModel({ type: [String, Number], default: '' })

const props = defineProps({
  type: { type: String, default: 'text' },
  size: { type: String, default: 'md' },
  placeholder: { type: String, default: '' },
  disabled: Boolean,
  invalid: Boolean,
})

const sizes = {
  sm: 'rounded-lg px-2.5 py-1.5 text-xs',
  md: 'rounded-xl px-3 py-2 text-sm',
}
</script>

<template>
  <input
    v-model="model"
    :type="props.type"
    :placeholder="props.placeholder"
    :disabled="props.disabled"
    class="w-full border bg-surface text-ink outline-none transition
      placeholder:text-ink-mute
      focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/15
      disabled:cursor-not-allowed disabled:opacity-60"
    :class="[sizes[props.size] ?? sizes.md, props.invalid ? 'border-rose-500/60' : 'border-line']"
  />
</template>
