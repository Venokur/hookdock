export function parseDate(value) {
  // `formatClock(updatedAt)` passes a live Date; stringifying it produces
  // "Thu Oct 29 2026 ...", which the normalisation below would corrupt.
  if (value instanceof Date) {
    return Number.isNaN(value.getTime()) ? null : value
  }

  if (!value) {
    return null
  }

  let normalized = String(value).trim().replace(' ', 'T')
  normalized = normalized.replace(/(\.\d{3})\d+/, '$1')
  normalized = normalized.replace(/([+-]\d{2})$/, '$1:00')

  const date = new Date(normalized)

  return Number.isNaN(date.getTime()) ? null : date
}

export function formatTime(value) {
  const date = parseDate(value)

  if (!date) {
    return '—'
  }

  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}

/** Time only — used for "обновлено 12:34:56" next to a refresh button. */
export function formatClock(value) {
  const date = parseDate(value)

  if (!date) {
    return '—'
  }

  return date.toLocaleTimeString('ru-RU', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}

export function formatDateTime(value) {
  const date = parseDate(value)

  return date ? date.toLocaleString('ru-RU') : '—'
}

export function formatBytes(bytes) {
  const size = Number(bytes || 0)

  if (size < 1024) {
    return `${size} Б`
  }
  if (size < 1024 * 1024) {
    return `${(size / 1024).toFixed(1)} КБ`
  }

  return `${(size / (1024 * 1024)).toFixed(2)} МБ`
}

export function timeAgo(value) {
  const date = parseDate(value)

  if (!date) {
    return 'никогда'
  }

  const seconds = Math.round((Date.now() - date.getTime()) / 1000)

  if (seconds < 60) return 'только что'
  if (seconds < 3600) return `${Math.floor(seconds / 60)} мин назад`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)} ч назад`

  return `${Math.floor(seconds / 86400)} дн назад`
}
