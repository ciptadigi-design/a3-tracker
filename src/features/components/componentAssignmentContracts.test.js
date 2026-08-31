import assert from "node:assert/strict";
import test from "node:test";
import {
  activeAssignmentSummary,
  archivedConfigurationActions,
  effectiveProfiles,
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
      slot_code: "GEAR-A", tracking_method: 'counter_based', current_profile_baseline: 100,
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

test("effective profiles match database scope and recency precedence", () => {
  const rows = [
    {
      id: "54000000-0000-0000-0000-000000000020",
      account_id: null,
      machine_model_id: "c1070",
      component_id: "gear",
      slot_code: " GEAR ",
      display_order: 20,
      is_active: true,
      created_at: "2026-08-27T05:12:50.209015Z",
    },
    {
      id: "095f8818-042f-452e-b829-5acf003ba764",
      account_id: "account",
      machine_model_id: "c1070",
      component_id: "gear",
      slot_code: "gear",
      display_order: 20,
      is_active: false,
      created_at: "2026-08-28T20:48:30.006024Z",
    },
    {
      id: "93328b69-30e7-41fb-a2f9-527e0a5bc558",
      account_id: "account",
      machine_model_id: "c1070",
      component_id: "gear",
      slot_code: "GEAR",
      display_order: 0,
      is_active: true,
      created_at: "2026-08-28T20:49:33.326588Z",
    },
    {
      id: "0175db65-e32c-4d35-a4df-007e422939ab",
      account_id: "account",
      machine_model_id: "c1070",
      component_id: "gear",
      slot_code: "GEAR_TEST",
      display_order: 0,
      is_active: false,
      created_at: "2026-08-28T20:52:31.906019Z",
    },
  ];

  const effective = effectiveProfiles(rows, "account", "c1070");
  assert.deepEqual(
    effective.map(({ id, slot_code, is_active }) => ({
      id,
      slot_code,
      is_active,
    })),
    [
      {
        id: "93328b69-30e7-41fb-a2f9-527e0a5bc558",
        slot_code: "GEAR",
        is_active: true,
      },
      {
        id: "0175db65-e32c-4d35-a4df-007e422939ab",
        slot_code: "GEAR_TEST",
        is_active: false,
      },
    ],
  );
});

test("newest archived workspace profile shadows an active shared default", () => {
  const effective = effectiveProfiles(
    [
      {
        id: "shared",
        account_id: null,
        machine_model_id: "c1070",
        slot_code: "GEAR-A",
        display_order: 1,
        is_active: true,
        created_at: "2026-08-27T00:00:00Z",
      },
      {
        id: "workspace-archived",
        account_id: "account",
        machine_model_id: "c1070",
        slot_code: "gear-a",
        display_order: 1,
        is_active: false,
        created_at: "2026-08-28T00:00:00Z",
      },
    ],
    "account",
    "c1070",
  );

  assert.equal(effective.length, 1);
  assert.equal(effective[0].id, "workspace-archived");
  assert.equal(effective[0].is_active, false);
});

test("effective profile recency preserves PostgreSQL microsecond ordering", () => {
  const effective = effectiveProfiles(
    [
      {
        id: "ffffffff-ffff-4fff-8fff-ffffffffffff",
        account_id: "account",
        machine_model_id: "c1070",
        slot_code: "GEAR",
        display_order: 1,
        is_active: false,
        created_at: "2026-08-28T20:49:33.326100+00:00",
      },
      {
        id: "00000000-0000-4000-8000-000000000000",
        account_id: "account",
        machine_model_id: "c1070",
        slot_code: "GEAR",
        display_order: 1,
        is_active: true,
        created_at: "2026-08-28T20:49:33.326588+00:00",
      },
    ],
    "account",
    "c1070",
  );

  assert.equal(effective[0].id, "00000000-0000-4000-8000-000000000000");
  assert.equal(effective[0].is_active, true);
});

test("DEV REST projection without created_at cannot crash effective resolution", () => {
  const effective = effectiveProfiles(
    [
      {
        id: "54000000-0000-0000-0000-000000000020",
        account_id: null,
        machine_model_id: "c1070",
        slot_code: "GEAR",
        display_order: 20,
        is_active: true,
      },
      {
        id: "93328b69-30e7-41fb-a2f9-527e0a5bc558",
        account_id: "account",
        machine_model_id: "c1070",
        slot_code: "GEAR",
        display_order: 0,
        is_active: true,
      },
      {
        id: "0175db65-e32c-4d35-a4df-007e422939ab",
        account_id: "account",
        machine_model_id: "c1070",
        slot_code: "GEAR_TEST",
        display_order: 0,
        is_active: false,
      },
    ],
    "account",
    "c1070",
  );

  assert.deepEqual(
    effective.map(({ id, slot_code, is_active }) => ({
      id,
      slot_code,
      is_active,
    })),
    [
      {
        id: "93328b69-30e7-41fb-a2f9-527e0a5bc558",
        slot_code: "GEAR",
        is_active: true,
      },
      {
        id: "0175db65-e32c-4d35-a4df-007e422939ab",
        slot_code: "GEAR_TEST",
        is_active: false,
      },
    ],
  );
});

test("effective resolver safely ignores malformed collections and slot identities", () => {
  assert.deepEqual(effectiveProfiles(undefined, "account", "c1070"), []);
  assert.deepEqual(effectiveProfiles(null, "account", "c1070"), []);
  assert.deepEqual(effectiveProfiles({}, "account", "c1070"), []);
  assert.deepEqual(
    effectiveProfiles(
      [
        null,
        { machine_model_id: "c1070", slot_code: null },
        { machine_model_id: "c1070", slot_code: "  " },
      ],
      "account",
      "c1070",
    ),
    [],
  );
});

test("Xerox zero-profile fixture never inherits Konica profiles", () => {
  const rows = Array.from({ length: 28 }, (_, index) => ({
    id: `konica-${index}`,
    account_id: "account",
    machine_model_id: "konica-c1070",
    slot_code: `KONICA-${index}`,
    display_order: index,
    is_active: true,
  }));
  assert.equal(effectiveProfiles(rows, "account", "konica-c1070").length, 28);
  assert.deepEqual(effectiveProfiles(rows, "account", "xerox-versant-180"), []);
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
        tracking_method: "counter_based", current_profile_baseline: 100,
      },
      canManage: true,
    }),
    { canRemove: true, canInitialize: true, showsSlotCode: true },
  );
});

test("active lifecycle cannot be detached and unconfigured manual UNKNOWN cannot initialize", () => {
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
        slot_code: "ROLLER-X", tracking_method: null, current_profile_baseline: null,
      },
      canManage: true,
    }).canInitialize,
    false,
  );
});

test("configured manual UNKNOWN can initialize without a profile slot", () => {
  assert.equal(machineComponentCapabilities({
    assignment: { lifecycle_status: 'unknown', assignment_status: 'configured', model_component_profile_id: null, tracking_method: 'counter_based', current_profile_baseline: 1200, slot_code: 'LOCAL-X' },
    canManage: true,
  }).canInitialize, true)
})

test("UNKNOWN lifecycle evidence remains history-protected from removal", () => {
  const capabilities = machineComponentCapabilities({
    assignment: {
      lifecycle_id: "legacy-sentinel",
      lifecycle_status: "unknown",
      model_component_profile_id: "profile",
      tracking_method: "counter_based", current_profile_baseline: 100,
      slot_code: "GEAR",
    },
    canManage: true,
  });

  assert.equal(capabilities.canRemove, false);
  assert.equal(capabilities.canInitialize, true);
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
