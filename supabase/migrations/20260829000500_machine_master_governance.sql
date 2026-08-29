-- Pre-M2.8 acceptance patch: governed Manufacturer and Machine Model masters.
-- Existing shared masters remain read-only; account masters are reusable across
-- every Branch and remain writable only through explicit Platform Superuser RLS.

create unique index manufacturers_shared_name_normalized_key
  on public.manufacturers (lower(btrim(name))) where account_id is null;
create unique index manufacturers_account_name_normalized_key
  on public.manufacturers (account_id,lower(btrim(name))) where account_id is not null;
create unique index machine_models_manufacturer_name_normalized_key
  on public.machine_models (manufacturer_id,lower(btrim(name)));

create or replace function public.validate_scoped_machine_catalog()
returns trigger
language plpgsql
set search_path = ''
as $$
declare
  manufacturer_account_id uuid;
  manufacturer_is_active boolean;
begin
  select manufacturer.account_id,manufacturer.is_active
  into manufacturer_account_id,manufacturer_is_active
  from public.manufacturers manufacturer
  where manufacturer.id=new.manufacturer_id;

  if not found then
    raise exception 'MANUFACTURER_NOT_FOUND' using errcode='23503';
  end if;
  if new.is_active and not manufacturer_is_active then
    raise exception 'MANUFACTURER_INACTIVE' using errcode='23514';
  end if;
  if new.account_id is null and manufacturer_account_id is not null then
    raise exception 'SHARED_MODEL_REQUIRES_SHARED_MANUFACTURER' using errcode='23514';
  end if;
  if new.account_id is not null and manufacturer_account_id is not null
      and manufacturer_account_id<>new.account_id then
    raise exception 'MACHINE_MASTER_SCOPE_MISMATCH' using errcode='23514';
  end if;
  return new;
end;
$$;

drop trigger machine_models_validate_scope on public.machine_models;
create trigger machine_models_validate_scope
before insert or update of manufacturer_id,account_id,is_active on public.machine_models
for each row execute function public.validate_scoped_machine_catalog();

create or replace function public.guard_manufacturer_archive()
returns trigger
language plpgsql
set search_path=''
as $$
begin
  if old.is_active and not new.is_active and exists(
    select 1 from public.machine_models model
    where model.manufacturer_id=old.id and model.is_active
  ) then
    raise exception 'MANUFACTURER_HAS_ACTIVE_MODELS' using errcode='23514';
  end if;
  return new;
end;
$$;

create trigger manufacturers_guard_archive
before update of is_active on public.manufacturers
for each row execute function public.guard_manufacturer_archive();

create or replace function public.validate_machine_model_assignment()
returns trigger
language plpgsql
set search_path=''
as $$
declare
  model_account_id uuid;
  catalog_is_active boolean;
begin
  if tg_op='UPDATE' and new.machine_model_id=old.machine_model_id then return new; end if;
  select model.account_id,(model.is_active and manufacturer.is_active)
  into model_account_id,catalog_is_active
  from public.machine_models model
  join public.manufacturers manufacturer on manufacturer.id=model.manufacturer_id
  where model.id=new.machine_model_id;
  if not found or not catalog_is_active then
    raise exception 'ACTIVE_MACHINE_MODEL_NOT_FOUND' using errcode='23503';
  end if;
  if model_account_id is not null and model_account_id<>new.account_id then
    raise exception 'MACHINE_MODEL_ACCOUNT_MISMATCH' using errcode='23503';
  end if;
  return new;
end;
$$;

revoke all on function public.guard_manufacturer_archive() from public,anon,authenticated,service_role;

comment on function public.guard_manufacturer_archive() is
  'Prevents Manufacturer archive while active Machine Models still depend on it.';
comment on index public.manufacturers_account_name_normalized_key is
  'One canonical Manufacturer name per account, reserved across archive/restore.';
comment on index public.machine_models_manufacturer_name_normalized_key is
  'One canonical Machine Model name per Manufacturer, reserved across archive/restore.';
