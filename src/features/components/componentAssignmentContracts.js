export function activeAssignmentSummary({
  profiles,
  models,
  accountId,
  resolveProfiles,
}) {
  const result = new Map();
  for (const model of models) {
    for (const profile of resolveProfiles(profiles, accountId, model.id).filter(
      (item) => item.is_active,
    )) {
      const summary = result.get(profile.component_id) ?? {
        models: [],
        slotCount: 0,
      };
      if (!summary.models.some((item) => item.id === model.id))
        summary.models.push(model);
      summary.slotCount += 1;
      result.set(profile.component_id, summary);
    }
  }
  return result;
}

function normalizedSlot(profile) {
  return profile.slot_code.trim().toLocaleLowerCase("en-US");
}

function effectiveProfilePrecedence(profile, accountId) {
  const parsedMilliseconds = Date.parse(profile.created_at);
  const fractionalSeconds = profile.created_at.match(/\.(\d+)/)?.[1] ?? "";
  const subMillisecondMicros = Number(
    fractionalSeconds.padEnd(6, "0").slice(3, 6),
  );
  return [
    profile.account_id === accountId ? 1 : 0,
    Number.isNaN(parsedMilliseconds)
      ? 0
      : parsedMilliseconds * 1000 + subMillisecondMicros,
    profile.id,
  ];
}

function hasHigherPrecedence(candidate, current, accountId) {
  const candidateRank = effectiveProfilePrecedence(candidate, accountId);
  const currentRank = effectiveProfilePrecedence(current, accountId);
  return (
    candidateRank[0] > currentRank[0] ||
    (candidateRank[0] === currentRank[0] &&
      (candidateRank[1] > currentRank[1] ||
        (candidateRank[1] === currentRank[1] &&
          candidateRank[2].localeCompare(currentRank[2]) > 0)))
  );
}

// Keep this resolver aligned with sync_machine_component_assignments_internal:
// normalized slot, workspace over shared, then newest created row and UUID.
// Activity is evaluated only after the effective row has been selected, so an
// archived workspace row intentionally shadows the shared default.
export function effectiveProfiles(profiles, accountId, modelId) {
  const bySlot = new Map();
  for (const profile of profiles.filter(
    (item) =>
      item.machine_model_id === modelId &&
      (item.account_id == null || item.account_id === accountId),
  )) {
    const slot = normalizedSlot(profile);
    const current = bySlot.get(slot);
    if (!current || hasHigherPrecedence(profile, current, accountId))
      bySlot.set(slot, profile);
  }
  return [...bySlot.values()].sort(
    (a, b) =>
      a.display_order - b.display_order || a.slot_code.localeCompare(b.slot_code),
  );
}

export function machineComponentCapabilities({ assignment, canManage }) {
  return {
    canRemove: Boolean(
      canManage &&
        assignment?.lifecycle_status === "unknown" &&
        assignment?.lifecycle_id == null,
    ),
    canInitialize: Boolean(
      canManage &&
        assignment?.lifecycle_status === "unknown" &&
        assignment?.model_component_profile_id,
    ),
    showsSlotCode: Boolean(assignment?.slot_code),
  };
}

export function archivedConfigurationActions({ isActive, canManage }) {
  if (!canManage) return [];
  return isActive ? ["edit", "archive"] : ["restore"];
}

export const PROFILE_SLOT_CONFLICT = "PROFILE_SLOT_CONFLICT";

export function profileRestoreConflict({ error, profile, model }) {
  if (![PROFILE_SLOT_CONFLICT, "23505"].includes(error?.code)) return null;

  const slotCode = profile?.slot_code || "this slot";
  const modelLabel = model?.name ? ` for ${model.name}` : " for this machine model";
  return {
    code: PROFILE_SLOT_CONFLICT,
    message: `Cannot restore this profile because an active profile already uses slot code ${slotCode}${modelLabel}.`,
  };
}
