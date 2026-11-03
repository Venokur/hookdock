/**
 * Date helpers for the app's own calendar.
 *
 * The stored value stays the same string the native `datetime-local`/`date`
 * inputs produced — `YYYY-MM-DD` or `YYYY-MM-DDTHH:mm` — because that is what
 * the API accepts (`expires_at` is a bare date) and what the saved filters in
 * `localStorage` already contain. Only the *display* and the picking are ours.
 */

export const MONTHS_NOMINATIVE = [
  'Январь',
  'Февраль',
  'Март',
  'Апрель',
  'Май',
  'Июнь',
  'Июль',
  'Август',
  'Сентябрь',
  'Октябрь',
  'Ноябрь',
  'Декабрь',
]

/** Calendar header reads «31 декабря», so the month is in the genitive case. */
export const MONTHS_GENITIVE = [
  'января',
  'февраля',
  'марта',
  'апреля',
  'мая',
  'июня',
  'июля',
  'августа',
  'сентября',
  'октября',
  'ноября',
  'декабря',
]

/** Monday first, like every Russian calendar. */
export const WEEKDAYS_SHORT = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']

export function pad2(value) {
  return String(value).padStart(2, '0')
}

/**
 * Stored value → parts. Hours/minutes are `null` for a bare date, and a value
 * that is not a date at all is `null` rather than an Invalid Date: the caller
 * has to be able to tell "empty" from "unparseable".
 */
export function parseParts(value) {
  if (typeof value !== 'string') {
    return null
  }

  const match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::\d{2})?)?$/.exec(value.trim())

  if (!match) {
    return null
  }

  const parts = {
    year: Number(match[1]),
    month: Number(match[2]) - 1,
    day: Number(match[3]),
    hour: match[4] === undefined ? null : Number(match[4]),
    minute: match[5] === undefined ? null : Number(match[5]),
  }

  return isValidParts(parts) ? parts : null
}

export function isValidParts(parts) {
  if (!parts) {
    return false
  }

  const { year, month, day } = parts

  if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) {
    return false
  }

  if (year < 1900 || year > 9999 || month < 0 || month > 11) {
    return false
  }

  if (day < 1 || day > daysInMonth(year, month)) {
    return false
  }

  const hour = parts.hour ?? 0
  const minute = parts.minute ?? 0

  return Number.isInteger(hour) && hour >= 0 && hour <= 23
    && Number.isInteger(minute) && minute >= 0 && minute <= 59
}

export function daysInMonth(year, month) {
  return new Date(year, month + 1, 0).getDate()
}

/** 0 = Monday … 6 = Sunday. */
export function weekdayIndex(year, month, day) {
  return (new Date(year, month, day).getDay() + 6) % 7
}

export function todayParts() {
  const now = new Date()

  return {
    year: now.getFullYear(),
    month: now.getMonth(),
    day: now.getDate(),
    hour: null,
    minute: null,
  }
}

export function nowParts() {
  const now = new Date()

  return {
    year: now.getFullYear(),
    month: now.getMonth(),
    day: now.getDate(),
    hour: now.getHours(),
    minute: now.getMinutes(),
  }
}

/** Comparison is day-granular: `min`/`max` in the calendar mean whole days. */
export function dayNumber(parts) {
  return parts.year * 10000 + parts.month * 100 + parts.day
}

export function isBeforeDay(a, b) {
  return dayNumber(a) < dayNumber(b)
}

export function isAfterDay(a, b) {
  return dayNumber(a) > dayNumber(b)
}

export function toIsoDate(parts) {
  return `${String(parts.year).padStart(4, '0')}-${pad2(parts.month + 1)}-${pad2(parts.day)}`
}

export function toIsoDateTime(parts) {
  return `${toIsoDate(parts)}T${pad2(parts.hour ?? 0)}:${pad2(parts.minute ?? 0)}`
}

/** What the field shows: `31.12.2026` or `31.12.2026, 18:30`. */
export function displayValue(parts, withTime = false) {
  if (!parts) {
    return ''
  }

  const date = `${pad2(parts.day)}.${pad2(parts.month + 1)}.${parts.year}`

  return withTime ? `${date}, ${pad2(parts.hour ?? 0)}:${pad2(parts.minute ?? 0)}` : date
}

/** Calendar header: «Декабрь 2026». */
export function monthLabel(year, month) {
  return `${MONTHS_NOMINATIVE[month]} ${year}`
}

/** Long label for the field's `title`: «31 декабря 2026, 18:30». */
export function longDate(parts, withTime = false) {
  if (!parts) {
    return ''
  }

  const date = `${parts.day} ${MONTHS_GENITIVE[parts.month]} ${parts.year}`

  return withTime ? `${date}, ${pad2(parts.hour ?? 0)}:${pad2(parts.minute ?? 0)}` : date
}

/**
 * Six weeks of cells starting on the Monday of the week that contains the 1st.
 * A 42-cell grid keeps the panel's height constant, so moving between months
 * does not make the popover jump.
 */
export function monthCells(year, month) {
  const start = new Date(year, month, 1)
  start.setDate(start.getDate() - weekdayIndex(year, month, 1))

  const cells = []

  for (let index = 0; index < 42; index += 1) {
    const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + index)

    cells.push({
      year: date.getFullYear(),
      month: date.getMonth(),
      day: date.getDate(),
      inMonth: date.getMonth() === month && date.getFullYear() === year,
    })
  }

  return cells
}