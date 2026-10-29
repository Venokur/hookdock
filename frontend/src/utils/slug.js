const TRANSLIT = {
  а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z',
  и: 'i', й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r',
  с: 's', т: 't', у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch',
  ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya',
}

/**
 * Mirrors RoomsController::slugify on the server so the preview in the room
 * form matches the value that will actually be stored.
 */
export function slugify(value) {
  const lower = String(value ?? '').toLowerCase()
  let out = ''

  for (const char of lower) {
    out += TRANSLIT[char] ?? char
  }

  return out
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 64)
}

/**
 * Mirrors RoomsController::insertWithGeneratedSlug so the read-only field shows
 * the slug the server is about to assign: the transliterated name, or the next
 * free `-2`, `-3`, … variant when the list already holds that slug.
 */
export function uniqueSlug(value, takenSlugs = []) {
  const base = slugify(value) || 'room'
  const taken = new Set([...takenSlugs].map((slug) => String(slug ?? '').toLowerCase()))

  if (!taken.has(base)) {
    return base
  }

  for (let n = 2; n <= 100; n++) {
    const suffix = `-${n}`
    const trimmed = base.slice(0, 64 - suffix.length).replace(/-+$/, '')
    const candidate = `${trimmed || 'room'}${suffix}`

    if (!taken.has(candidate)) {
      return candidate
    }
  }

  return base
}
