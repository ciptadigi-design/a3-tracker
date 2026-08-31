import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const page = readFileSync(
  new URL("../../pages/ComponentsPage.jsx", import.meta.url),
  "utf8",
);
const dialog = readFileSync(
  new URL("./MachineComponentDialog.jsx", import.meta.url),
  "utf8",
);
const styles = readFileSync(new URL("../../App.css", import.meta.url), "utf8");
const service = readFileSync(
  new URL("../../services/supabase/components.js", import.meta.url),
  "utf8",
);

test("Machine Components exposes explicit add, sync, remove, and restore language", () => {
  for (const label of [
    "Add Machine-Specific Component",
    "Sync Model Profile",
    "Remove from Machine",
    "Restore to Machine",
  ])
    assert.match(page, new RegExp(label));
  assert.match(page, /Model Profile is not changed/);
});

test("archived configuration uses Restore and slot codes remain visible", () => {
  assert.match(page, /profile\.slot_code/);
  assert.match(page, /RotateCcw/);
  assert.match(page, /assignmentSummary\.slotCount/);
});

test("all three Components tabs retain renderable page sections", () => {
  for (const label of [
    "Machine Components",
    "Model Profiles",
    "Component Catalog",
  ])
    assert.match(page, new RegExp(label));
  assert.match(page, /view\.tab === 'machine' && <MachineComponentsPanel/);
  assert.match(page, /view\.tab === 'profiles' && <>/);
  assert.match(page, /view\.tab === 'catalog' && <>/);
});

test("profile payload includes resolver recency and load failures keep a safe page state", () => {
  assert.match(service, /created_at,/);
  assert.match(page, /Components could not be loaded\./);
  assert.match(page, /role="alert"/);
  assert.doesNotMatch(page, /\{error\.message\}/);
});

test("machine-specific add uses BlockingDialog with accessible dismissal and busy state", () => {
  assert.match(dialog, /BlockingDialog/);
  assert.match(dialog, /aria-label="Close machine component form"/);
  assert.match(dialog, /busy=\{busy\}/);
  assert.match(dialog, /Standard components belong in Model Profiles/);
});

test("machine-specific add uses one searchable Catalog combobox", () => {
  assert.match(dialog, /role="combobox"/);
  assert.match(dialog, /aria-controls="machine-component-catalog-options"/);
  assert.match(dialog, /Search or choose component/);
  assert.match(dialog, /role="listbox"/);
  assert.match(dialog, /No matching component found\./);
  assert.match(dialog, /Create New Component/);
  assert.doesNotMatch(dialog, /<option value="">Choose component<\/option>/);
  assert.doesNotMatch(dialog, /Search component name or code/);
});

test("Catalog combobox preserves explicit creation and supported tracking choices", () => {
  assert.match(dialog, /onKeyDown/);
  assert.match(dialog, /ArrowDown/);
  assert.match(dialog, /ArrowUp/);
  assert.match(dialog, /event\.key === "Enter"/);
  assert.match(dialog, /event\.key === "Escape"/);
  assert.match(dialog, /Consumption based \(Coming soon\)/);
  assert.match(dialog, /Inspection based \(Coming soon\)/);
  assert.match(dialog, /value="consumption_based" disabled/);
  assert.match(dialog, /value="inspection_based" disabled/);
  assert.match(dialog, /onCreateNewComponent\?\.\(value\)/);
});

test("zero configuration state does not imply fabricated lifecycle", () => {
  assert.match(page, /No components configured for this machine/);
  assert.match(page, /No lifecycle or inventory movement was fabricated/);
});

test("profile Restore remains callable, compact, and reports success", () => {
  assert.match(page, /onClick=\{\(\) => restoreProfile\(profile\)\}/);
  assert.match(page, /const restoreAction = [^\n]+className="secondary-button compact-action"/);
  assert.match(page, /aria-label=\{`Restore \$\{profile\.components\?\.name\} \$\{profile\.slot_code\}`\}/);
  assert.match(page, /Model Profile restored\. Eligible machines were synchronized/);
  assert.match(page, /disabled=\{busy\}/);
});

test("known profile restore conflict is contextual and never renders raw database details", () => {
  assert.match(page, /profileRestoreConflict/);
  assert.match(page, /className="profile-restore-conflict" role="alert" aria-live="assertive"/);
  assert.match(page, /Profile could not be restored\./);
  assert.match(service, /error\?\.code === '23505' && action === 'restore'/);
  assert.match(service, /conflict\.code = 'PROFILE_SLOT_CONFLICT'/);
  assert.doesNotMatch(page, /machine_model_components_active_slot_key|duplicate key value/);
});

test("Catalog action hierarchy keeps every action callable and accessible", () => {
  assert.match(page, /onClick=\{\(\) => open\('profile-assign', component\.id\)\}/);
  assert.match(page, /secondary-button compact-action catalog-edit-action/);
  assert.match(page, /onClick=\{\(\) => open\('component-edit', component\.id\)\}/);
  assert.match(page, /secondary-button compact-action compact-icon-action/);
  assert.match(page, /aria-label=\{`Archive component \$\{component\.name\}`\}/);
  assert.match(page, /onClick=\{\(\) => restoreComponent\(component\)\}/);
});

test("shared compact actions preserve content width and responsive wrapping", () => {
  assert.match(styles, /\.compact-action \{[^}]*width: fit-content;[^}]*min-height: 32px/);
  assert.match(styles, /\.compact-icon-action \{ width: 32px; padding: 0; \}/);
  assert.match(styles, /\.compact-catalog-actions \{[^}]*flex-wrap: wrap/);
  assert.match(styles, /\.page-header \.components-page-actions/);
  assert.equal((page.match(/Sync Model Profile/g) ?? []).length, 1);
  assert.equal((page.match(/Add Machine-Specific Component/g) ?? []).length, 1);
});
