import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const source = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const page = source('../../pages/ComponentsPage.jsx')
const styles = source('../../App.css')

const editableDraftForms = [
  './ComponentDialog.jsx',
  './InitializeLifecycleDialog.jsx',
  './ProfileDialog.jsx',
  './ReplaceComponentDialog.jsx',
  '../incidents/IncidentFormDialog.jsx',
  '../inventory/InventoryDialogs.jsx',
  '../inventory/PurchasingDialogs.jsx',
  '../machineCost/OperatingCostDialog.jsx',
  '../machineCost/SellingPriceDialog.jsx',
  '../machines/MachineFormDialog.jsx',
  '../operationalMasters/MasterRecordDialog.jsx',
]

test('Components uses one responsive shell and context-row grammar for every tab', () => {
  assert.match(page, /components-page-actions/)
  assert.match(page, /component-toolbar components-shell-header/)
  assert.match(page, /components-context-row machine-components-context/)
  assert.match(page, /components-context-row model-profiles-context/)
  assert.match(page, /components-context-row catalog-components-context/)
  assert.match(styles, /\.components-context-row \{[^}]*grid-template-columns:/)
  assert.match(styles, /\.components-shell-header > \.machine-view-tabs/)
})

test('mobile Components actions, tabs, and contextual controls stay compact and flexible', () => {
  assert.match(styles, /\.page-header \.components-page-actions \{[^}]*flex-wrap: nowrap/)
  assert.match(styles, /\.components-shell-header > \.machine-view-tabs button \{[^}]*flex: 1 1 0/)
  assert.match(styles, /\.machine-component-toolbar \{[^}]*grid-template-columns: repeat\(2,minmax\(0,1fr\)\)/)
  assert.match(styles, /@media \(max-width: 430px\) \{\s*\.components-shell-header \{ grid-template-columns: minmax\(0,1fr\); \}/)
})

test('component headers stay operationally compact and detailed lifecycle actions retain hierarchy', () => {
  assert.doesNotMatch(page, /Standard components for this model belong in Model Profiles/)
  assert.doesNotMatch(page, /Model Profile defines standard component slots inherited by machines of this model/)
  assert.match(styles, /\.machine-components-context \{[^}]*grid-template-columns:/)
  assert.match(styles, /\.initialize-lifecycle-button \{[^}]*box-shadow: none/)
  assert.match(styles, /\.unknown-lifecycle-body \.row-actions \{[^}]*justify-content: space-between/)
  assert.match(page, /initialize-lifecycle-button/)
  assert.match(page, /Remove from Machine/)
})

test('persistent-draft dialogs share the accessible editable footer contract', () => {
  for (const path of editableDraftForms) {
    const form = source(path)
    assert.match(form, /dialog-actions form-action-footer/, path)
    assert.match(form, /aria-label="Reset draft"/, path)
    assert.match(form, /title="Reset draft"/, path)
  }
  const profile = source('./ProfileDialog.jsx')
  assert.match(profile, /resetDraft\(initial\)/)
  assert.match(profile, /disabled=\{!hasDraft \|\| saving\}/)
})

test('mobile editable footers remain one row with submit priority and icon-only reset', () => {
  assert.match(styles, /\.machine-form > \.form-action-footer \{[^}]*flex-direction: row-reverse/)
  assert.match(styles, /\.machine-form > \.form-action-footer > :last-child \{[^}]*flex: 1 1 auto/)
  assert.match(styles, /\.machine-form > \.form-action-footer > \.draft-reset-button \{[^}]*flex: 0 0 38px[^}]*font-size: 0/)
  assert.match(styles, /\.draft-reset-button:focus-visible/)
})

test('destructive and read-only dialogs remain outside the editable footer contract', () => {
  for (const path of [
    '../machines/RetireMachineDialog.jsx',
    '../machineCost/VoidOperatingCostDialog.jsx',
    '../inventory/InventoryMovementDetailDialog.jsx',
  ]) assert.doesNotMatch(source(path), /form-action-footer/, path)
})
