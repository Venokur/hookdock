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

/** What a caller sees when the search itself broke, not the endpoint list. */
const FAILED_HINT = 'Не удалось загрузить список — попробуйте ещё раз.'

/**
 * Shortest term that goes to the server.
 *
 * `endpoint ILIKE '%q%'` is served by a trigram index, and a trigram needs three
 * characters: at one or two Postgres cannot extract a single one and falls back to
 * scanning the room's rows — twice per pause in typing, since the panel asks on
 * every 250 ms heartbeat of the keyboard. Below the threshold the panel keeps the
 * short list it already has and says so, which is both cheaper and more honest
 * than a request whose answer cannot be selective.
 */
const MIN_TERM = 3

export function useEndpointSearch(getSlug) {
  const term = ref('')
  const hits = ref([])
  const searching = ref(false)
  const failed = ref(false)
  const meta = reactive({ total: 0, returned: 0, has_more: false })
  /** The term whose answer `hits`/`meta`/`failed` describe. */
  const answered = ref('')
  let seq = 0

  /**
   * @param stats  the `stats` payload, or null while it is still loading
   * @param blank  an optional leading option, e.g. {value:'', label:'Все эндпоинты'}
   * @param current  the value that must stay listed even if it is not in `stats`
   * @param allowCustom  offer the typed term as a value when nothing matched it
   */
  function options(stats, { allowCustom = false, blank = null, current = '' } = {}) {
    const typed = term.value.trim()
    // Too short a term is not a search: the panel keeps showing the room's short
    // list, exactly as if the field were empty.
    const filtering = typed.length >= MIN_TERM
    // Matches appear only once the answer for *this* term has arrived. While a
    // request is in flight the previous term's rows would sit under a box that no
    // longer says that term, and clicking one would pick a value the operator
    // stopped searching for.
    const fresh = answered.value === term.value
    const source = !filtering ? (stats?.endpoints ?? []) : fresh ? hits.value : []
    const known = source.map((item) => ({ value: item.endpoint, label: item.endpoint }))
    const selected = String(current ?? '').trim()

    // Without case, for the same reason as the custom row below: the value came
    // back from a rule written as `HOOK-1`, and listing it next to the stored
    // `hook-1` would offer two rows that route to one endpoint.
    if (selected !== '' && !known.some((option) => String(option.value).toLowerCase() === selected.toLowerCase())) {
      known.unshift({ value: selected, label: selected })
    }

    if (allowCustom && typed !== '') {
      const lower = typed.toLowerCase()

      // Compared without case: routing matches an endpoint case-insensitively
      // (`WorkflowEngine::endpointMatches`), so `HOOK-1` next to `hook-1` would be
      // two rows that do the same thing. Offered even below the search threshold:
      // a short slug is legal, and this row needs no server to answer.
      if (!known.some((option) => String(option.value).toLowerCase() === lower)) {
        known.push({ value: typed, label: `Использовать «${typed}»` })
      }
    }

    return blank ? [blank, ...known] : known
  }

  /**
   * One line under the list. `failedText` is per caller: the log filter has no
   * manual input, so telling it to "type the slug yourself" is advice it cannot
   * follow.
   */
  function hint({
    total = 0,
    shown = 0,
    failedText = FAILED_HINT,
    pendingText = '',
  } = {}) {
    const typed = term.value.trim()

    if (typed !== '') {
      // Too short to ask the server: the list still shows the room's top
      // endpoints, and saying nothing here would leave the operator wondering why
      // typing changed nothing.
      if (typed.length < MIN_TERM) {
        return `Введите минимум ${MIN_TERM} символа — короткий запрос не сужает список.`
      }

      // Nothing is known about this term yet: "ничего не найдено" while the
      // request is still running would state a fact the server has not answered.
      if (answered.value !== term.value) {
        return pendingText
      }

      if (failed.value) {
        return failedText
      }

      if (meta.total === 0) {
        return 'Ничего не найдено.'
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
    // and a request per deleted character would only add noise. A term too short to
    // be selective does the same, with a line that says why.
    if (next === '' || next.length < MIN_TERM) {
      reset()
      term.value = next
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
        answered.value = next
        searching.value = false
      }
    }
  }

  /** Drops a term and any in-flight answer, for a room switch or a closed panel. */
  function reset() {
    seq += 1
    term.value = ''
    answered.value = ''
    hits.value = []
    searching.value = false
    failed.value = false
    Object.assign(meta, { total: 0, returned: 0, has_more: false })
  }

  // `term`/`hits`/`failed`/`meta` stay internal: both callers describe the panel
  // through `options`/`hint`, and a second, partial view of the same state would
  // eventually disagree with them.
  return { options, hint, search, searching, reset }
}
