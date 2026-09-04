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
  if (typeof profile?.slot_code !== "string") return null;
  const slot = profile.slot_code.trim();
  return slot ? slot.toLocaleLowerCase("en-US") : null;
}

function effectiveProfilePrecedence(profile, accountId) {
  const createdAt =
    typeof profile?.created_at === "string" ? profile.created_at : "";
  const parsedMilliseconds = Date.parse(createdAt);
  const fractionalSeconds = createdAt.match(/\.(\d+)/)?.[1] ?? "";
  const subMillisecondMicros = Number(
    fractionalSeconds.padEnd(6, "0").slice(3, 6),
  );
  return [
    profile.account_id === accountId ? 1 : 0,
    Number.isNaN(parsedMilliseconds)
      ? 0
      : parsedMilliseconds * 1000 + subMillisecondMicros,
    typeof profile?.id === "string" ? profile.id : "",
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
  const candidates = Array.isArray(profiles) ? profiles : [];
  for (const profile of candidates.filter(
    (item) =>
      item?.machine_model_id === modelId &&
      (item.account_id == null || item.account_id === accountId),
  )) {
    const slot = normalizedSlot(profile);
    if (!slot) continue;
    const current = bySlot.get(slot);
    if (!current || hasHigherPrecedence(profile, current, accountId))
      bySlot.set(slot, profile);
  }
  return [...bySlot.values()].sort(
    (a, b) => {
      const aOrder = Number.isFinite(Number(a.display_order))
        ? Number(a.display_order)
        : Number.MAX_SAFE_INTEGER;
      const bOrder = Number.isFinite(Number(b.display_order))
        ? Number(b.display_order)
        : Number.MAX_SAFE_INTEGER;
      return aOrder - bOrder || String(a.slot_code).localeCompare(String(b.slot_code));
    },
  );
}

// UNINITIALIZED_UNKNOWN ('unknown': zero lifecycle evidence) and BASELINE_KNOWN ('baseline_known': a
// status='unknown' lifecycle row with a real installed_counter but no factual installation date) both
// remain eligible to Initialize (confirm a real started_at) or Remove — neither is treated as active.
function isUninitializedLifecycleStatus(lifecycleStatus) {
  return lifecycleStatus === "unknown" || lifecycleStatus === "baseline_known";
}

export function machineComponentCapabilities({ assignment, canManage }) {
  const config = effectiveTrackingConfiguration(assignment)
  return {
    canRemove: Boolean(
      canManage &&
        isUninitializedLifecycleStatus(assignment?.lifecycle_status) &&
        assignment?.lifecycle_id == null,
    ),
    canInitialize: Boolean(
      canManage &&
        isUninitializedLifecycleStatus(assignment?.lifecycle_status) &&
        assignment?.assignment_status !== 'retired' &&
        config.complete,
    ),
    showsSlotCode: Boolean(assignment?.slot_code),
  };
}

export function effectiveTrackingConfiguration(assignment) {
  const trackingMethod = assignment?.tracking_method ?? null
  const baseline = Number(assignment?.current_profile_baseline ?? assignment?.baseline_expected_clicks)
  return { trackingMethod, baseline: Number.isFinite(baseline) && baseline > 0 ? baseline : null, complete: Boolean(trackingMethod && Number.isFinite(baseline) && baseline > 0) }
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
