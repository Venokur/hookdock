<script setup>
/**
 * The project mark: one line in, two out.
 *
 * It replaced a bare "H" that had been copy-pasted into five places (each copy
 * drifting a little), and it is drawn rather than typed so the same shape reads
 * at 16px in a browser tab, at 18px in the mobile header and at 32px on the
 * empty state. The geometry is the one in `public/favicon.svg` — a trunk, a
 * split, and two branches — because the sidebar and the tab are the same logo
 * and a second, hand-tuned drawing would eventually disagree with the first.
 *
 * The gradient is the wrapper's Tailwind background, not an SVG `<defs>`: the
 * mark can appear twice on one page, and duplicate gradient ids resolve to the
 * first definition in the document — which breaks as soon as that first one is
 * inside a hidden container.
 */
const props = defineProps({
  size: { type: String, default: 'md' },
})

const sizes = {
  sm: { box: 'h-8 w-8 rounded-lg', icon: 'h-[18px] w-[18px]' },
  md: { box: 'h-9 w-9 rounded-xl', icon: 'h-5 w-5' },
  lg: { box: 'h-14 w-14 rounded-2xl', icon: 'h-8 w-8' },
}
</script>

<template>
  <span
    class="grid shrink-0 place-items-center bg-gradient-to-br from-indigo-500 to-sky-500 text-white shadow-lg shadow-indigo-500/25"
    :class="sizes[props.size]?.box ?? sizes.md.box"
  >
    <svg
      viewBox="0 0 32 32"
      fill="none"
      stroke="currentColor"
      stroke-width="3.4"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
      :class="sizes[props.size]?.icon ?? sizes.md.icon"
    >
      <path d="M8 16h5.2" />
      <path d="M13.2 16A5.2 5.2 0 0 0 18.4 10.8H23.6" />
      <path d="M13.2 16A5.2 5.2 0 0 1 18.4 21.2H23.6" />
    </svg>
  </span>
</template>
