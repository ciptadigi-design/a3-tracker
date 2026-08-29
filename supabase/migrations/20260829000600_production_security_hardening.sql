-- M2.8 production hardening: trigger helpers are internal implementation details
-- and must not retain PostgreSQL's default PUBLIC execute privilege.

revoke all on function public.validate_profile_username() from public,anon,authenticated,service_role;

comment on function public.validate_profile_username() is
  'Internal profile username validation trigger; not directly executable through the Data API.';
