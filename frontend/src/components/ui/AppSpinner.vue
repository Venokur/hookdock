<script setup>
/**
 * One spinner for the whole app. Size is fixed by the caller, colour comes from
 * `currentColor`, so it inherits the surrounding text colour.
 */
const props = defineProps({
  size: { type: String, default: 'sm' },
})

const sizes = {
  xs: 'h-3 w-3 border-2',
  sm: 'h-4 w-4 border-2',
  md: 'h-6 w-6 border-2',
}
</script>

<template>
  <span
    class="inline-block shrink-0 animate-spin rounded-full border-current border-t-transparent"
    :class="sizes[props.size] ?? sizes.sm"
    aria-hidden="true"
  ></span>
</template>
