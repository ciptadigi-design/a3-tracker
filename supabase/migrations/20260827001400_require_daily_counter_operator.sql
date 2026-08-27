-- M2.3E follow-up: retire authenticated access to the legacy counter RPC.
-- Historical rows with null operators remain valid; new Daily submissions use
-- the operator-aware overload introduced by 20260827001300.

revoke execute on function public.record_machine_counter(
  uuid, uuid, numeric, timestamptz, uuid, text, text, text
) from authenticated;

comment on function public.record_machine_counter(
  uuid, uuid, numeric, timestamptz, uuid, text, text, text
) is 'Legacy compatibility signature. Not executable by client roles after M2.3E.';
