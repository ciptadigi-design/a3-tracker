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

test("Machine Components exposes explicit add, sync, remove, and restore language", () => {
  for (const label of [
    "Add Component",
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

test("machine-specific add uses BlockingDialog with accessible dismissal and busy state", () => {
  assert.match(dialog, /BlockingDialog/);
  assert.match(dialog, /aria-label="Close machine component form"/);
  assert.match(dialog, /busy=\{busy\}/);
  assert.match(dialog, /This does not change its Model Profile/);
});

test("zero configuration state does not imply fabricated lifecycle", () => {
  assert.match(page, /No components configured for this machine/);
  assert.match(page, /No lifecycle or inventory movement was fabricated/);
});
