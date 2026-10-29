/**
 * Endpoint slugs are one URL segment of a webhook address, so they use the same
 * alphabet as room slugs. `RoomsController::validEndpoint` validates the value
 * again on the server; this module only mirrors it for the form preview.
 */
const ENDPOINT_PATTERN = /^[a-z0-9][a-z0-9-]{0,63}$/

export function isValidEndpoint(value) {
  return ENDPOINT_PATTERN.test(String(value ?? '').trim().toLowerCase())
}

/**
 * Mirrors `RoomsController::generateEndpoint`: a random tail behind `hook-` so
 * addresses handed out by different rooms never look alike.
 */
export function randomEndpoint() {
  const bytes = new Uint8Array(3)
  crypto.getRandomValues(bytes)

  return `hook-${Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')}`
}
