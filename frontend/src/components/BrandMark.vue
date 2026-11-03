<script setup>
/**
 * The project mark: the letter H on a graphite tile.
 *
 * A monogram rather than a pictogram: the fork drawn earlier said "routing"
 * more precisely, but it did not survive 16px in a browser tab without turning
 * into a blob, and a wordmark that only works at 48px is not a favicon. The H
 * is the product's initial and it keeps its shape at every size down to 16px.
 *
 * The geometry is the one in `public/favicon.svg` — three capsules in a 32-unit
 * box, stems at 9.6 and 22.4 from y=8 to y=24, crossbar at y=16 — so the tab,
 * the sidebar and the splash are one drawing. Do not hand-tune a second copy:
 * that is exactly how the five typed "H"s this replaced drifted apart.
 *
 * The tile colour is a flat token, not a gradient. Graphite is `#0f172a`, the
 * same value as the light theme's `--ink`, so the mark belongs to the palette
 * instead of fighting the status colours (green = success, red = failure).
 * A wrapper class, not an SVG `<defs>`: the mark renders twice per page
 * (sidebar + mobile header, one hidden by CSS), and a duplicated fill id would
 * resolve to the first definition and break inside the hidden container.
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
    class="grid shrink-0 place-items-center bg-[#0f172a] text-white shadow-lg shadow-slate-900/25 ring-1 ring-inset ring-white/10"
    :class="sizes[props.size]?.box ?? sizes.md.box"
  >
    <svg
      viewBox="0 0 32 32"
      fill="none"
      stroke="currentColor"
      stroke-width="3.6"
      stroke-linecap="round"
      aria-hidden="true"
      :class="sizes[props.size]?.icon ?? sizes.md.icon"
    >
      <path d="M9.6 8v16" />
      <path d="M22.4 8v16" />
      <path d="M9.6 16h12.8" />
    </svg>
  </span>
</template>
