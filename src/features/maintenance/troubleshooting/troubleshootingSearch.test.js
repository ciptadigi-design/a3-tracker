import assert from 'node:assert/strict'
import test from 'node:test'
import { FIELD_PAGE_SIZE, OFFICIAL_PAGE_SIZE, createTroubleshootingSearch, initialSearchState } from './troubleshootingSearch.js'
import { classifyTroubleshootingQuery } from './troubleshootingModel.js'

const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b }); return { promise, resolve, reject } }
const page = (items, current = 1, last = 1, total = items.length) => ({ data: items, meta: { current_page: current, last_page: last, total } })
const official = (id, code = 'C-0213') => ({ id, code, classification: 'Fuser error', applicabilities: [] })
const experience = (id, extra = {}) => ({ id, title: 'Hasil Kotor', error_code: 'C-0213', cause: 'Drum kotor', action: 'Drum dibersihkan', test_result: 'normal', model_match: false, same_machine: false, ...extra })

function harness({ officialImpl, fieldImpl } = {}) {
  const calls = { official: [], field: [] }
  const states = []
  const controller = createTroubleshootingSearch({
    accountId: 'acc-1',
    searchOfficial: (query, options) => { calls.official.push({ query, ...options }); return (officialImpl ?? (async () => page([])))(query, options) },
    searchField: (args) => { calls.field.push(args); return (fieldImpl ?? (async () => page([])))(args) },
    onChange: (state) => states.push(state),
  })
  return { controller, calls, states }
}

test('query classification routes codes, 4-digit numbers, partial codes and descriptions', () => {
  const kinds = (q) => classifyTroubleshootingQuery(q).kind
  for (const q of ['C-0213', 'C0213', 'c0213', 'c-0213', ' c 0213 ']) assert.equal(kinds(q), 'code', q)
  for (const q of ['J-2113', 'J2113', 'j2113']) assert.equal(kinds(q), 'code', q)
  assert.equal(kinds('0213'), 'numeric')
  assert.equal(kinds('2113'), 'numeric')
  assert.equal(kinds('cc152'), 'code')
  for (const q of ['152', 'C-02', 'j21']) assert.equal(kinds(q), 'partial', q)
  for (const q of ['hasil kotor', 'paper exit', 'motor tidak berfungsi', 'cetak', 'jalan', 'toner habis']) assert.equal(kinds(q), 'text', q)
  assert.equal(kinds(''), 'empty')
  assert.equal(kinds('   '), 'empty')

  assert.deepEqual(classifyTroubleshootingQuery('j2113').codes, ['J-2113'])
  assert.deepEqual(classifyTroubleshootingQuery('0213').codes, ['C-0213', 'J-0213'])
  // Official keeps its legacy default (bare digits = C); descriptions are never turned into fake codes.
  assert.equal(classifyTroubleshootingQuery('0213').officialQuery, 'C-0213')
  assert.equal(classifyTroubleshootingQuery('J-2113').officialQuery, 'J-2113')
  for (const q of ['hasil kotor', 'paper exit', 'motor tidak berfungsi']) assert.equal(classifyTroubleshootingQuery(q).officialQuery, null)
  assert.equal(classifyTroubleshootingQuery('a').fieldSkipReason, 'too_short')
  assert.equal(classifyTroubleshootingQuery('a b').fieldQuery, null)
  assert.equal(classifyTroubleshootingQuery('a bc').fieldQuery, 'a bc')
})

test('explicit C-code and J-code search both sources with the normalized code for official', async () => {
  for (const [typed, officialQuery] of [['c0213', 'C-0213'], ['J2113', 'J-2113']]) {
    const { controller, calls } = harness({ officialImpl: async () => page([official('o1')]), fieldImpl: async () => page([experience('f1')]) })
    await controller.run(typed, { machineId: 'm-1' })
    assert.equal(calls.official.length, 1)
    assert.equal(calls.official[0].query, officialQuery)
    assert.equal(calls.field.length, 1)
    assert.equal(calls.field[0].query, typed, 'the backend interprets the field query')
    assert.equal(calls.field[0].accountId, 'acc-1')
    assert.equal(calls.field[0].machineId, 'm-1')
  }
})

test('numeric-only search keeps C and J apart: official stays C, field gets the raw digits', async () => {
  const { controller, calls } = harness({ officialImpl: async () => page([official('o1')]), fieldImpl: async () => page([experience('f1', { error_code: 'C-0213' }), experience('f2', { error_code: 'J-0213' })]) })
  await controller.run('0213')
  assert.equal(calls.official[0].query, 'C-0213')
  assert.equal(calls.field[0].query, '0213')
  const state = controller.getState()
  assert.equal(state.kind, 'numeric')
  assert.deepEqual(state.codes, ['C-0213', 'J-0213'])
  assert.deepEqual(state.field.items.map((item) => item.error_code), ['C-0213', 'J-0213'], 'identities are never merged or rewritten')
  assert.equal(state.official.items[0].code, 'C-0213')
})

test('descriptive search only queries field experiences and never calls official search', async () => {
  for (const q of ['hasil kotor', 'paper exit', 'motor tidak berfungsi']) {
    const { controller, calls } = harness({ fieldImpl: async () => page([experience('f1')]) })
    await controller.run(q)
    assert.equal(calls.official.length, 0, q)
    assert.equal(calls.field[0].query, q)
    assert.equal(controller.getState().official.status, 'skipped')
    assert.equal(controller.getState().field.status, 'success')
  }
})

test('official-only, field-only, combined and both-empty outcomes are independent', async () => {
  let h = harness({ officialImpl: async () => page([official('o1')]), fieldImpl: async () => page([]) })
  await h.controller.run('C-0213')
  assert.deepEqual([h.controller.getState().official.status, h.controller.getState().field.status], ['success', 'empty'])

  h = harness({ officialImpl: async () => page([]), fieldImpl: async () => page([experience('f1')]) })
  await h.controller.run('J-2113')
  assert.deepEqual([h.controller.getState().official.status, h.controller.getState().field.status], ['empty', 'success'])

  h = harness({ officialImpl: async () => page([official('o1')]), fieldImpl: async () => page([experience('f1')], 1, 1, 1) })
  await h.controller.run('C-0213')
  assert.deepEqual([h.controller.getState().official.status, h.controller.getState().field.status], ['success', 'success'])

  h = harness({ officialImpl: async () => page([]), fieldImpl: async () => page([]) })
  await h.controller.run('C-9999')
  assert.deepEqual([h.controller.getState().official.status, h.controller.getState().field.status], ['empty', 'empty'])
})

test('a failing source never hides the other and is an error, not an empty result', async () => {
  let h = harness({ officialImpl: async () => { throw new Error('official down') }, fieldImpl: async () => page([experience('f1')]) })
  await h.controller.run('C-0213')
  let state = h.controller.getState()
  assert.equal(state.official.status, 'error')
  assert.equal(state.official.items.length, 0)
  assert.equal(state.official.error.message, 'official down')
  assert.equal(state.field.status, 'success')
  assert.equal(state.field.items.length, 1)

  h = harness({ officialImpl: async () => page([official('o1')]), fieldImpl: async () => { throw new Error('field down') } })
  await h.controller.run('C-0213')
  state = h.controller.getState()
  assert.equal(state.field.status, 'error')
  assert.equal(state.official.status, 'success')
  assert.equal(state.official.items[0].id, 'o1')

  h = harness({ officialImpl: async () => { throw new Error('a') }, fieldImpl: async () => { throw new Error('b') } })
  await h.controller.run('C-0213')
  assert.deepEqual([h.controller.getState().official.status, h.controller.getState().field.status], ['error', 'error'])
})

test('each group has its own loading state and resolves independently', async () => {
  const slowOfficial = deferred()
  const fastField = deferred()
  const { controller, states } = harness({ officialImpl: () => slowOfficial.promise, fieldImpl: () => fastField.promise })
  const running = controller.run('C-0213')
  assert.deepEqual([controller.getState().official.status, controller.getState().field.status], ['loading', 'loading'])

  fastField.resolve(page([experience('f1')]))
  await new Promise((resolve) => setTimeout(resolve, 0))
  assert.deepEqual([controller.getState().official.status, controller.getState().field.status], ['loading', 'success'], 'field shows while official is still loading')

  slowOfficial.resolve(page([official('o1')]))
  await running
  assert.deepEqual([controller.getState().official.status, controller.getState().field.status], ['success', 'success'])
  assert.ok(states.length >= 4)
})

test('retry re-runs only the failed group', async () => {
  let officialCalls = 0
  const { controller, calls } = harness({
    officialImpl: async () => { officialCalls += 1; if (officialCalls === 1) throw new Error('boom'); return page([official('o1')]) },
    fieldImpl: async () => page([experience('f1')]),
  })
  await controller.run('C-0213')
  assert.equal(controller.getState().official.status, 'error')
  await controller.retry('field') // not failed: nothing happens
  assert.equal(calls.field.length, 1)
  await controller.retry('official')
  assert.equal(calls.field.length, 1, 'field untouched by the official retry')
  assert.equal(calls.official.length, 2)
  assert.equal(controller.getState().official.status, 'success')
})

test('field pagination uses the backend page size and appends deterministic pages without duplicates', async () => {
  assert.ok(FIELD_PAGE_SIZE <= 25 && OFFICIAL_PAGE_SIZE <= 25, 'respects the backend caps')
  assert.equal(OFFICIAL_PAGE_SIZE, 20)
  const pages = { 1: page([experience('a'), experience('b')], 1, 3, 5), 2: page([experience('b'), experience('c')], 2, 3, 5), 3: page([experience('d')], 3, 3, 5) }
  const { controller, calls } = harness({ fieldImpl: async ({ page: n }) => pages[n] })
  await controller.run('hasil kotor')
  let field = controller.getState().field
  assert.deepEqual([field.items.map((i) => i.id), field.total, field.hasMore, field.page], [['a', 'b'], 5, true, 1])
  assert.equal(calls.field[0].page, 1)
  assert.equal(calls.field[0].perPage, FIELD_PAGE_SIZE)

  await controller.loadMore('field')
  field = controller.getState().field
  assert.deepEqual(field.items.map((i) => i.id), ['a', 'b', 'c'], 'overlapping row is de-duplicated, order is preserved')
  assert.equal(field.hasMore, true)
  await controller.loadMore('field')
  field = controller.getState().field
  assert.deepEqual(field.items.map((i) => i.id), ['a', 'b', 'c', 'd'])
  assert.equal(field.hasMore, false)
  await controller.loadMore('field') // nothing left: no extra request
  assert.deepEqual(calls.field.map((c) => c.page), [1, 2, 3])
})

test('official pagination loads the next backend page and keeps the first page on failure', async () => {
  let fail = false
  const pages = { 1: page([official('o1', 'C-1103'), official('o2', 'C-1103')], 1, 2, 3), 2: page([official('o3', 'C-1103')], 2, 2, 3) }
  const { controller, calls } = harness({ officialImpl: async (_q, { page: n }) => { if (fail) throw new Error('page 2 down'); return pages[n] } })
  await controller.run('C1103')
  assert.deepEqual(controller.getState().official.items.map((i) => i.id), ['o1', 'o2'])
  assert.equal(controller.getState().official.total, 3)
  assert.equal(calls.official[0].perPage, OFFICIAL_PAGE_SIZE)

  fail = true
  await controller.loadMore('official')
  let group = controller.getState().official
  assert.equal(group.status, 'success', 'a failed later page is not a failed search')
  assert.deepEqual(group.items.map((i) => i.id), ['o1', 'o2'])
  assert.equal(group.moreError.message, 'page 2 down')
  assert.equal(group.hasMore, true)

  fail = false
  await controller.loadMore('official')
  group = controller.getState().official
  assert.deepEqual(group.items.map((i) => i.id), ['o1', 'o2', 'o3'])
  assert.equal(group.moreError, null)
  assert.equal(group.hasMore, false)
})

test('a newer search supersedes an older one and late responses are ignored', async () => {
  const first = deferred()
  const { controller } = harness({ fieldImpl: ({ query }) => (query === 'hasil kotor' ? first.promise : Promise.resolve(page([experience('new')]))) })
  const older = controller.run('hasil kotor')
  await controller.run('paper exit')
  first.resolve(page([experience('stale')]))
  await older
  assert.deepEqual(controller.getState().field.items.map((i) => i.id), ['new'])
  assert.equal(controller.getState().query, 'paper exit')
})

test('too-short queries skip the field call instead of triggering a backend 422; empty queries reset', async () => {
  const { controller, calls } = harness()
  await controller.run('a')
  assert.equal(calls.field.length, 0)
  assert.equal(controller.getState().field.status, 'skipped')
  assert.equal(controller.getState().fieldSkipReason, 'too_short')
  await controller.run('')
  assert.deepEqual(controller.getState(), { ...initialSearchState(), classified: controller.getState().classified })
  assert.equal(controller.getState().kind, 'empty')
})

test('a disposed controller never emits late results (unmount safety) and can be re-attached', async () => {
  const slow = deferred()
  const { controller, states } = harness({ fieldImpl: () => slow.promise })
  const running = controller.run('hasil kotor')
  const before = states.length
  controller.dispose()
  slow.resolve(page([experience('f1')]))
  await running
  assert.equal(states.length, before)
  controller.attach()
  await controller.run('paper exit')
  assert.ok(states.length > before)
})
