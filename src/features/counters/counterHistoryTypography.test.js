import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'

const component = fs.readFileSync(new URL('./CounterHistory.jsx', import.meta.url), 'utf8')
const styles = fs.readFileSync(new URL('../../App.css', import.meta.url), 'utf8')

function rule(className) {
  return styles.match(new RegExp(`\\.history-time \\.${className} \\{([^}]*)\\}`))?.[1] || ''
}

function pixelValue(css, property) {
  return Number(css.match(new RegExp(`${property}:\\s*(\\d+)px`))?.[1])
}

test('counter history explicitly identifies operator and recorder hierarchy', () => {
  assert.match(component, /className="history-operator"/)
  assert.match(component, /className="history-recorder"/)
  assert.match(component, /history-operator[^>]*>\{reading\.shift_code/)
  assert.match(component, /history-recorder[^>]*>Recorded by/)
})

test('shift and operator is larger and more prominent than recorded by', () => {
  const operator = rule('history-operator')
  const recorder = rule('history-recorder')

  assert.ok(pixelValue(operator, 'font-size') > pixelValue(recorder, 'font-size'))
  assert.match(operator, /color:\s*var\(--text\)/)
  assert.match(operator, /font-weight:\s*700/)
  assert.match(recorder, /color:\s*var\(--text-muted\)/)
  assert.match(recorder, /font-weight:\s*500/)
  assert.match(operator, /overflow-wrap:\s*anywhere/)
})

test('timestamp remains larger than shift and operator', () => {
  const timestampSize = pixelValue(styles.match(/\.history-time strong \{([^}]*)\}/)?.[1] || '', 'font-size')
  const operatorSize = pixelValue(rule('history-operator'), 'font-size')

  assert.ok(timestampSize > operatorSize)
})
