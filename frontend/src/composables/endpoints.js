/**
 * Endpoint options for a room, shared by the request log's filter and the
 * workflow editor's trigger.
 *
 * Both ask the same question — "which endpoints does this room receive on" — and
 * both need the same split: a short list to show when the panel opens, and a
 * server search for everything else, because one room can receive on hundreds of
 * paths while `stats` returns only the most frequent few.
 *
 * Two deliberate extras live here rather than in each caller:
 *
 * - The selected value is always in the list. A value that fell out of the top
 *   fifteen is still active, and a select that cannot show its own current value
 *   renders as "everything" while the data stays filtered.
 * - `allowCustom` lets the caller accept a term that matched nothing. The log
 *   filter does not need it (filtering by an endpoint that never appeared is
 *   pointless), but a workflow does: a rule may be created before the first
 *   delivery arrives, and a picker that only offered seen endpoints would make
 *   "route /hook/room/orders" impossible to write in advance.
 *
 * Answers are discarded by sequence number, not by term: a slow reply for an
 * older term must not repaint matches for the current one.
 */
import { reactive, ref } from 'vue'
import { api } from '../api/client'

export function useEndpointSearch(getSlug) {
  const term = ref('')
  const hits = ref([])
  const searching = ref(false)
  const failed = ref(false)
  const meta = reactive({ total: 0, returned: 0, has_more: false })
  let seq = 0

  /**
   * @param stats  the `stats` payload, or null while it is still loading
   * @param blank  an optional leading option, e.g. {value:'', label:'Все эндпоинты'}
   * @param current  the value that must stay listed even if it is not in `stats`
   * @param allowCustom  offer the typed term as a value when nothing matched it
   */
  function options(stats, { allowCustom = false, blank = null, current = '' } = {}) {
    const source = term.value.trim() === '' ? (stats?.endpoints ?? []) : hits.value
    const known = source.map((item) => ({ value: item.endpoint, label: item.endpoint }))
    const selected = String(current ?? '').trim()

    if (selected !== '' && !known.some((option) => option.value === selected)) {
      known.unshift({ value: selected, label: selected })
    }

    if (allowCustom) {
      const typed = term.value.trim()

      // The typed term becomes a row only when nothing matched it: a duplicate
      // that selects the same string as a real result would read as two choices.
      if (typed !== '' && !known.some((option) => option.value === typed)) {
        known.push({ value: typed, label: `Использовать «${typed}»` })
      }
    }

    return blank ? [blank, ...known] : known
  }

  function hint({ total = 0, shown = 0 } = {}) {
    const typed = term.value.trim()

    if (typed !== '') {
      if (failed.value) {
        return 'Не удалось загрузить список — введите slug вручную.'
      }

      if (meta.total === 0) {
        return searching.value ? '' : 'Ничего не найдено.'
      }

      return meta.has_more
        ? `Найдено ${meta.total}, показаны первые ${meta.returned} — уточните запрос.`
        : `Найдено ${meta.total}.`
    }

    return total > shown ? `Показаны ${shown} из ${total} — остальные через поиск.` : ''
  }

  async function search(next) {
    term.value = next
    failed.value = false

    // Clearing the field goes back to the short list: there is nothing to fetch,
    // and a request per deleted character would only add noise.
    if (next === '') {
      reset()
      return
    }

    const mine = ++seq
    searching.value = true

    try {
      const res = await api.endpoints({ room: getSlug(), q: next, limit: 15 })

      if (mine !== seq || next !== term.value) {
        return
      }

      hits.value = res.data
      Object.assign(meta, res.meta)
    } catch {
      // A failed search is not an empty one: erasing the list and saying "ничего
      // не найдено" would report "this endpoint does not exist" when the truth is
      // "the request failed", so the panel says which of the two it is.
      if (mine === seq) {
        hits.value = []
        Object.assign(meta, { total: 0, returned: 0, has_more: false })
        failed.value = true
      }
    } finally {
      if (mine === seq) {
        searching.value = false
      }
    }
  }

  /** Drops a term and any in-flight answer, for a room switch or a closed panel. */
  function reset() {
    seq += 1
    term.value = ''
    hits.value = []
    searching.value = false
    failed.value = false
    Object.assign(meta, { total: 0, returned: 0, has_more: false })
  }

  return { term, hits, searching, failed, meta, options, hint, search, reset }
}
