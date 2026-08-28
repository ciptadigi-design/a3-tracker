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

export function machineComponentCapabilities({ assignment, canManage }) {
  return {
    canRemove: Boolean(canManage && assignment?.lifecycle_status === "unknown"),
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
