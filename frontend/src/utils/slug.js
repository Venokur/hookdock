/**
 * Room slugs are one URL segment of a webhook address, so they use the same
 * alphabet as endpoint slugs. `RoomsController::validSlug` validates the value
 * again on the server; this module mirrors the rules for the form.
 */
const SLUG_PATTERN = /^[a-z0-9][a-z0-9-]{0,63}$/
const SLUG_BYTES = 6

export function isValidSlug(value) {
  return SLUG_PATTERN.test(String(value ?? '').trim().toLowerCase())
}

/**
 * Mirrors `RoomsController::generateSlug`: two 12-char hex groups with no
 * relation to the room name, so renaming a room never changes its address.
 */
export function randomSlug() {
  const group = () => {
    const bytes = new Uint8Array(SLUG_BYTES)
    crypto.getRandomValues(bytes)

    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  }

  return `${group()}-${group()}`
}
