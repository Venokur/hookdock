const TRANSLIT = {
  а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z',
  и: 'i', й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r',
  с: 's', т: 't', у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch',
  ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya',
}

/**
 * Mirrors RoomsController::validSlug on the server so the field shows the value
 * that will actually be stored, instead of failing a round trip later.
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

export const SLUG_PATTERN = /^[a-z0-9][a-z0-9-]{0,63}$/
