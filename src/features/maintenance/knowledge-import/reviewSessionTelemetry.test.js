import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

// V1.11 - Review Workflow Benchmark instrumentation. Same static source-inspection convention as
// groupPublishReview.test.js (no component-rendering harness exists in this domain); the pure
// active-time model itself is exercised for real in reviewSessionActivity.test.js.
const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const hook = read('./useReviewSession.js')
const detail = read('./CodeGroupDetailDialog.jsx')
const sourceCtx = read('./SourcePageContext.jsx')
const panel = read('./ReviewBenchmarkPanel.jsx')
const services = read('../../../services/maintenance.js')
const lib = read('../../../lib/api/maintenance.js')
// Code only, comments stripped - a docblock explaining what the UI deliberately avoids must not
// itself trip an "avoid this" assertion.
const panelCode = panel.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')

// 1. a review session begins on real group detail

test('the code group detail dialog starts a review session for the opened group', () => {
  assert.match(detail, /import \{ useReviewSession \} from '\.\/useReviewSession\.js'/)
  assert.match(detail, /const session = useReviewSession\(\{ importId, code \}\)/)
  assert.match(hook, /startReviewSession\(importId, code\)/)
})

// 2/3/4. visibility + inactivity govern active-time accumulation (background tab, inactivity, resume)

test('the tracker only accumulates active time via the shared visibility/inactivity model, never a raw wall-clock diff', () => {
  assert.match(hook, /activeSecondsForTick\(/)
  assert.doesNotMatch(hook, /published_at\s*-\s*opened_at/, 'no naive wall-clock calculation')
  assert.match(hook, /document\.visibilityState/)
})

// 5. source-page interaction tracked

test('a source-page interaction increments the local counter via SourcePageContext', () => {
  assert.match(detail, /onSourceView=\{session\.recordSourceView\}/)
  assert.match(sourceCtx, /onSourceView\?\.\(\)/)
})

// 6. authoring transition tracked

test('the first canonical-draft edit transitions the session to AUTHORING_STARTED exactly once', () => {
  assert.match(detail, /session\.recordAuthoringEdit\(\)/)
  assert.match(detail, /authoringStartedRef\.current = true; session\.setStage\('AUTHORING_STARTED'\)/)
})

// 7. save transition tracked

test('a successful publish preview transitions the session to AUTHORING_SAVED', () => {
  assert.match(detail, /function handlePreviewed\(previewData\)/)
  assert.match(detail, /if \(previewData\?\.can_publish\) session\.setStage\('AUTHORING_SAVED'\)/)
  assert.match(detail, /onPreviewed=\{handlePreviewed\}/)
})

// 8. validation failure tracked

test('a blocked publish request (validation) increments validation_failures', () => {
  assert.match(detail, /if \(!canRequestPreview\(\{ published, canonicalId, draft \}\)\) \{ setShowErrors\(true\); session\.recordValidationFailure\(\); return \}/)
})

// 9. publish completion tracked

test('a real publish marks the session published', () => {
  assert.match(detail, /function handlePublished\(\)\s*\{\s*session\.markPublished\(\)/)
  assert.match(detail, /onPublished=\{handlePublished\}/)
})

// 10. telemetry failure does NOT block normal review/publish workflow

test('every telemetry network call is wrapped so its failure cannot throw into the review workflow', () => {
  const catches = hook.match(/\.catch\(\(\) => \{[^}]*telemetry only[^}]*\}\)/g) ?? []
  assert.ok(catches.length >= 3, 'start/heartbeat/abandon calls must each swallow their own errors')
  assert.doesNotMatch(hook, /await startReviewSession/, 'starting a session must never block the group detail dialog from rendering')
})

// 11. benchmark summary limited-sample state

test('the benchmark panel shows an explicit limited-sample note and never a raw average', () => {
  assert.match(panel, /limited_sample/)
  assert.match(panel, /Limited sample/)
  assert.match(panel, /Median/i)
  assert.doesNotMatch(panel, /[Aa]verage/, 'medians only, never averages, per the milestone brief')
})

// 12. no reviewer ranking UI

test('the benchmark panel never renders per-reviewer identity, ranking or comparison', () => {
  assert.doesNotMatch(panelCode, /reviewer_user_id|reviewer_name|leaderboard|top performer/i)
})

// structural: instrumentation endpoints cannot reach publish/approve/reject/restore

test('review-session service calls are a closed set with no publish/approve/reject capability', () => {
  assert.match(services, /export const startReviewSession/)
  assert.match(services, /export const heartbeatReviewSession/)
  assert.match(services, /export const abandonReviewSession/)
  assert.match(services, /export const loadReviewBenchmark/)
  assert.match(lib, /review-session\/start/)
  assert.match(lib, /review-sessions\/\$\{sessionId\}\/heartbeat/)
})
