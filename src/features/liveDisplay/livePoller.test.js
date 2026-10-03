import { after, before, beforeEach, mock, test } from 'node:test'
import assert from 'node:assert/strict'
import { setImmediate as setImmediateReal } from 'node:timers'
import { createLivePoller } from './livePoller.js'

// fetchData resolves/rejects as a real Promise; mock.timers only fakes the
// macro timer queue (setTimeout), so every assertion that depends on a
// fetch's outcome has to flush the microtask queue for real first.
const flush = () => new Promise((resolve) => setImmediateReal(resolve))

before(() => mock.timers.enable({ apis: ['setTimeout'] }))
after(() => mock.timers.reset())

function makePoller({ outcomes, baseDelayMs = 30_000, backoffDelaysMs = [30_000, 60_000, 120_000] } = {}) {
  const calls = []
  const results = []
  let index = 0
  const queue = outcomes ?? []
  const fetchData = mock.fn(async () => {
    calls.push(Date.now())
    const outcome = queue[Math.min(index, queue.length - 1)]
    index += 1
    if (outcome?.fail) throw outcome.error ?? new Error('fetch failed')
    return outcome?.data ?? {}
  })
  const poller = createLivePoller({ fetchData, onResult: (result) => results.push(result), baseDelayMs, backoffDelaysMs })
  return { poller, fetchData, results }
}

let activePollers = []
beforeEach(() => { activePollers = [] })
after(() => activePollers.forEach((poller) => poller.stop()))

function track(poller) { activePollers.push(poller); return poller }

test('start() fetches immediately, exactly once', async () => {
  const { poller, fetchData } = makePoller({ outcomes: [{ data: {} }] })
  track(poller)
  poller.start()
  await flush()
  assert.equal(fetchData.mock.callCount(), 1)
})

test('a successful refresh reports success and schedules the next refresh at the base delay', async () => {
  const { poller, fetchData, results } = makePoller({ outcomes: [{ data: { ok: 1 } }, { data: { ok: 2 } }] })
  track(poller)
  poller.start()
  await flush()
  assert.equal(results[0].success, true)
  assert.deepEqual(results[0].data, { ok: 1 })

  mock.timers.tick(29_999)
  await flush()
  assert.equal(fetchData.mock.callCount(), 1, 'must not refetch before the base delay elapses')

  mock.timers.tick(1)
  await flush()
  assert.equal(fetchData.mock.callCount(), 2)
})

test('consecutive failures back off 30s -> 60s -> 120s and cap at 120s', async () => {
  const { poller, fetchData } = makePoller({ outcomes: [{ fail: true }, { fail: true }, { fail: true }, { fail: true }, { data: {} }] })
  track(poller)
  poller.start()
  await flush()
  assert.equal(fetchData.mock.callCount(), 1)

  mock.timers.tick(30_000)
  await flush()
  assert.equal(fetchData.mock.callCount(), 2, 'first failure retries after 30s')

  mock.timers.tick(60_000)
  await flush()
  assert.equal(fetchData.mock.callCount(), 3, 'second consecutive failure retries after 60s')

  mock.timers.tick(120_000)
  await flush()
  assert.equal(fetchData.mock.callCount(), 4, 'third consecutive failure retries after 120s (cap)')

  mock.timers.tick(120_000)
  await flush()
  assert.equal(fetchData.mock.callCount(), 5, 'failures beyond the third stay capped at 120s')
})

test('recovery after a failure resets the interval back to the 30s base', async () => {
  const { poller, fetchData, results } = makePoller({ outcomes: [{ fail: true }, { data: {} }, { data: {} }] })
  track(poller)
  poller.start()
  await flush()
  mock.timers.tick(30_000)
  await flush()
  assert.equal(results[1].success, true, 'second attempt succeeded')

  mock.timers.tick(29_999)
  await flush()
  assert.equal(fetchData.mock.callCount(), 2, 'post-recovery delay is the 30s base, not a leftover backoff step')
  mock.timers.tick(1)
  await flush()
  assert.equal(fetchData.mock.callCount(), 3)
})

test('a failure keeps reporting failureCount so last-good data can be retained by the caller', async () => {
  const { poller, results } = makePoller({ outcomes: [{ fail: true }] })
  track(poller)
  poller.start()
  await flush()
  assert.equal(results[0].success, false)
  assert.equal(results[0].failureCount, 1)
})

test('pause() stops further scheduled refreshes until resumed', async () => {
  const { poller, fetchData } = makePoller({ outcomes: [{ data: {} }, { data: {} }] })
  track(poller)
  poller.start()
  await flush()
  poller.pause()

  mock.timers.tick(60_000)
  await flush()
  assert.equal(fetchData.mock.callCount(), 1, 'paused poller must not refetch')
})

test('resume() refetches immediately and then restarts the normal interval', async () => {
  const { poller, fetchData } = makePoller({ outcomes: [{ data: {} }, { data: {} }, { data: {} }] })
  track(poller)
  poller.start()
  await flush()
  poller.pause()
  poller.resume()
  await flush()
  assert.equal(fetchData.mock.callCount(), 2, 'resume triggers an immediate refetch')

  mock.timers.tick(30_000)
  await flush()
  assert.equal(fetchData.mock.callCount(), 3)
})

test('resume() is a no-op when not currently paused', async () => {
  const { poller, fetchData } = makePoller({ outcomes: [{ data: {} }] })
  track(poller)
  poller.start()
  await flush()
  poller.resume()
  await flush()
  assert.equal(fetchData.mock.callCount(), 1)
})

test('stop() permanently halts the loop', async () => {
  const { poller, fetchData } = makePoller({ outcomes: [{ data: {} }, { data: {} }] })
  track(poller)
  poller.start()
  await flush()
  poller.stop()

  mock.timers.tick(120_000)
  await flush()
  assert.equal(fetchData.mock.callCount(), 1)
})

test('calling start() again does not create a second concurrent timer chain', async () => {
  const { poller, fetchData } = makePoller({ outcomes: [{ data: {} }, { data: {} }, { data: {} }, { data: {} }] })
  track(poller)
  poller.start()
  poller.start()
  await flush()
  const callsAfterDoubleStart = fetchData.mock.callCount()

  mock.timers.tick(30_000)
  await flush()
  assert.equal(fetchData.mock.callCount(), callsAfterDoubleStart + 1, 'exactly one scheduled refresh fires per interval, regardless of how many times start() was called')
})
