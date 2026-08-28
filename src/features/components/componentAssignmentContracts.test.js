import assert from "node:assert/strict";
import test from "node:test";
import {
  activeAssignmentSummary,
  archivedConfigurationActions,
  machineComponentCapabilities,
  PROFILE_SLOT_CONFLICT,
  profileRestoreConflict,
} from "./componentAssignmentContracts.js";

const model = { id: "c1070", name: "C1070" };
const profiles = [
  {
    id: "a",
    machine_model_id: "c1070",
    component_id: "gear",
    slot_code: "GEAR-A",
    is_active: true,
  },
  {
    id: "b",
    machine_model_id: "c1070",
    component_id: "gear",
    slot_code: "GEAR-B",
    is_active: true,
  },
  {
    id: "old",
    machine_model_id: "c1070",
    component_id: "gear",
    slot_code: "GEAR-OLD",
    is_active: false,
  },
];

test("same logical Gear reports one model and two active physical slots", () => {
  const result = activeAssignmentSummary({
    profiles,
    models: [model],
    accountId: "account",
    resolveProfiles: (rows) => rows,
  });
  assert.equal(result.get("gear").models.length, 1);
  assert.equal(result.get("gear").slotCount, 2);
});

test("archived Catalog/Profile cards expose Restore only to managers", () => {
  assert.deepEqual(
    archivedConfigurationActions({ isActive: false, canManage: true }),
    ["restore"],
  );
  assert.deepEqual(
    archivedConfigurationActions({ isActive: false, canManage: false }),
    [],
  );
});

test("uninitialized inherited slot can initialize and remove", () => {
  assert.deepEqual(
    machineComponentCapabilities({
      assignment: {
        lifecycle_status: "unknown",
        model_component_profile_id: "profile",
        slot_code: "GEAR-A",
      },
      canManage: true,
    }),
    { canRemove: true, canInitialize: true, showsSlotCode: true },
  );
});

test("active lifecycle cannot be detached and manual UNKNOWN has no profile initialization action", () => {
  assert.equal(
    machineComponentCapabilities({
      assignment: {
        lifecycle_status: "active",
        model_component_profile_id: "profile",
        slot_code: "GEAR-A",
      },
      canManage: true,
    }).canRemove,
    false,
  );
  assert.equal(
    machineComponentCapabilities({
      assignment: {
        lifecycle_status: "unknown",
        model_component_profile_id: null,
        slot_code: "ROLLER-X",
      },
      canManage: true,
    }).canInitialize,
    false,
  );
});

test("operator cannot restructure machine configuration", () => {
  assert.equal(
    machineComponentCapabilities({
      assignment: {
        lifecycle_status: "unknown",
        model_component_profile_id: "profile",
        slot_code: "GEAR-A",
      },
      canManage: false,
    }).canRemove,
    false,
  );
});

test("profile restore uniqueness collision becomes a safe business conflict", () => {
  const conflict = profileRestoreConflict({
    error: {
      code: "23505",
      message:
        'duplicate key value violates unique constraint "machine_model_components_active_slot_key"',
    },
    profile: { slot_code: "GEAR" },
    model,
  });

  assert.deepEqual(conflict, {
    code: PROFILE_SLOT_CONFLICT,
    message:
      "Cannot restore this profile because an active profile already uses slot code GEAR for C1070.",
  });
  assert.doesNotMatch(conflict.message, /machine_model_components|duplicate key|uuid/i);
});

test("profile restore accepts a future database business code and ignores unexpected errors", () => {
  assert.equal(
    profileRestoreConflict({
      error: { code: PROFILE_SLOT_CONFLICT },
      profile: { slot_code: "GEAR_TEST" },
    })?.code,
    PROFILE_SLOT_CONFLICT,
  );
  assert.equal(
    profileRestoreConflict({
      error: { code: "42501" },
      profile: { slot_code: "GEAR" },
      model,
    }),
    null,
  );
});
