-- PRE-M2.5C data contract: optional SKU and one explicit canonical Inventory
-- Item for every active Component used by an active machine in the account.

alter table public.inventory_items
  alter column sku drop not null,
  add column is_canonical boolean not null default false;

alter table public.inventory_items
  drop constraint inventory_items_sku_not_blank,
  add constraint inventory_items_sku_not_blank check (sku is null or btrim(sku) <> ''),
  add constraint inventory_items_canonical_component_required check (not is_canonical or component_id is not null);

create unique index inventory_items_account_component_canonical_key
  on public.inventory_items(account_id,component_id) where is_canonical;

alter table public.inventory_purchase_lines
  alter column item_sku_snapshot drop not null,
  drop constraint inventory_purchase_lines_item_sku_snapshot_not_blank,
  add constraint inventory_purchase_lines_item_sku_snapshot_not_blank
    check (item_sku_snapshot is null or btrim(item_sku_snapshot) <> '');

alter table public.inventory_receipt_lines
  alter column item_sku_snapshot drop not null,
  drop constraint inventory_receipt_lines_item_sku_snapshot_not_blank,
  add constraint inventory_receipt_lines_item_sku_snapshot_not_blank
    check (item_sku_snapshot is null or btrim(item_sku_snapshot) <> '');

create or replace function public.validate_inventory_receipt_line()
returns trigger language plpgsql set search_path = '' as $$
declare
  receipt_record public.inventory_receipts%rowtype;
  purchase_line_record public.inventory_purchase_lines%rowtype;
  movement_record public.inventory_movements%rowtype;
begin
  select * into receipt_record from public.inventory_receipts where id = new.receipt_id;
  select * into purchase_line_record from public.inventory_purchase_lines where id = new.purchase_line_id;
  select * into movement_record from public.inventory_movements where id = new.inventory_movement_id;
  if receipt_record.id is null or purchase_line_record.id is null or movement_record.id is null
    or purchase_line_record.purchase_id <> receipt_record.purchase_id
    or purchase_line_record.inventory_item_id <> new.inventory_item_id
    or purchase_line_record.unit_price <> new.unit_price_snapshot
    or purchase_line_record.unit_snapshot <> new.unit_snapshot
    or purchase_line_record.item_sku_snapshot is distinct from new.item_sku_snapshot
    or purchase_line_record.item_name_snapshot <> new.item_name_snapshot
    or movement_record.account_id <> new.account_id
    or movement_record.inventory_item_id <> new.inventory_item_id
    or movement_record.location_id <> receipt_record.location_id
    or movement_record.movement_type <> 'receipt'
    or movement_record.reference_type <> 'purchase_receipt'
    or movement_record.reference_id <> receipt_record.id
    or movement_record.quantity <> new.quantity
    or movement_record.unit_snapshot <> new.unit_snapshot
    or movement_record.occurred_at <> receipt_record.received_at
    or movement_record.operational_person_id <> receipt_record.operational_person_id
    or movement_record.operational_person_name_snapshot <> receipt_record.operational_person_name_snapshot then
    raise exception 'receipt line does not match purchase, receipt, and inventory movement facts' using errcode = '23514';
  end if;
  return new;
end;
$$;

create function public.provision_canonical_inventory_items_for_account(target_account_id uuid)
returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare canonical_count integer;
begin
  if not exists (select 1 from public.accounts account where account.id = target_account_id) then
    raise exception 'account not found' using errcode = 'P0002';
  end if;

  -- A sole active linked item is an unambiguous existing default. Multiple
  -- linked items are variants, so a new explicit canonical item is created.
  with eligible as (
    select distinct component.id as component_id
    from public.components component
    join public.machine_model_components profile on profile.component_id = component.id and profile.is_active
    join public.machines machine on machine.machine_model_id = profile.machine_model_id
      and machine.account_id = target_account_id and machine.is_active
    where component.is_active
      and (component.account_id is null or component.account_id = target_account_id)
      and (profile.account_id is null or profile.account_id = target_account_id)
  ), sole_existing as (
    select item.component_id,min(item.id::text)::uuid as item_id
    from public.inventory_items item
    join eligible on eligible.component_id = item.component_id
    where item.account_id = target_account_id and item.is_active
      and not exists (
        select 1 from public.inventory_items canonical
        where canonical.account_id = target_account_id
          and canonical.component_id = item.component_id and canonical.is_canonical
      )
    group by item.component_id
    having count(*) = 1
  )
  update public.inventory_items item set is_canonical = true
  from sole_existing existing where item.id = existing.item_id;

  with eligible as (
    select distinct component.id,component.name,component.category
    from public.components component
    join public.machine_model_components profile on profile.component_id = component.id and profile.is_active
    join public.machines machine on machine.machine_model_id = profile.machine_model_id
      and machine.account_id = target_account_id and machine.is_active
    where component.is_active
      and (component.account_id is null or component.account_id = target_account_id)
      and (profile.account_id is null or profile.account_id = target_account_id)
  )
  insert into public.inventory_items(account_id,component_id,sku,name,category,unit,minimum_stock,notes,is_active,is_canonical)
  select target_account_id,eligible.id,null,eligible.name,eligible.category,'pcs',null,
    'Canonical Inventory Item provisioned from the active Component Catalog.',true,true
  from eligible
  where not exists (
    select 1 from public.inventory_items item
    where item.account_id = target_account_id and item.component_id = eligible.id and item.is_canonical
  )
  on conflict (account_id,component_id) where is_canonical do nothing;

  select count(*)::integer into canonical_count
  from public.inventory_items item
  where item.account_id = target_account_id and item.is_canonical;
  return canonical_count;
end;
$$;

revoke all on function public.provision_canonical_inventory_items_for_account(uuid)
  from public,anon,authenticated,service_role;

create function public.sync_canonical_inventory_items(target_account_id uuid)
returns integer
language plpgsql
security definer
set search_path = ''
as $$
begin
  if (select auth.uid()) is null then
    raise exception 'authentication required' using errcode = '42501';
  end if;
  if not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required' using errcode = '42501';
  end if;
  return public.provision_canonical_inventory_items_for_account(target_account_id);
end;
$$;

revoke all on function public.sync_canonical_inventory_items(uuid) from public,anon,authenticated,service_role;
grant execute on function public.sync_canonical_inventory_items(uuid) to authenticated,service_role;

create function public.sync_canonical_inventory_after_machine_change()
returns trigger language plpgsql security definer set search_path = '' as $$
begin
  if new.is_active then perform public.provision_canonical_inventory_items_for_account(new.account_id); end if;
  return new;
end;
$$;

create function public.sync_canonical_inventory_after_profile_change()
returns trigger language plpgsql security definer set search_path = '' as $$
declare account_record record;
begin
  if new.is_active then
    for account_record in
      select distinct machine.account_id from public.machines machine
      where machine.machine_model_id = new.machine_model_id and machine.is_active
        and (new.account_id is null or new.account_id = machine.account_id)
    loop
      perform public.provision_canonical_inventory_items_for_account(account_record.account_id);
    end loop;
  end if;
  return new;
end;
$$;

create function public.sync_canonical_inventory_after_component_change()
returns trigger language plpgsql security definer set search_path = '' as $$
declare account_record record;
begin
  if new.is_active then
    for account_record in
      select distinct machine.account_id
      from public.machine_model_components profile
      join public.machines machine on machine.machine_model_id = profile.machine_model_id and machine.is_active
      where profile.component_id = new.id and profile.is_active
        and (profile.account_id is null or profile.account_id = machine.account_id)
        and (new.account_id is null or new.account_id = machine.account_id)
    loop
      perform public.provision_canonical_inventory_items_for_account(account_record.account_id);
    end loop;
  end if;
  return new;
end;
$$;

revoke all on function public.sync_canonical_inventory_after_machine_change() from public,anon,authenticated,service_role;
revoke all on function public.sync_canonical_inventory_after_profile_change() from public,anon,authenticated,service_role;
revoke all on function public.sync_canonical_inventory_after_component_change() from public,anon,authenticated,service_role;

create trigger machines_sync_canonical_inventory
after insert or update of is_active,machine_model_id,account_id on public.machines
for each row execute function public.sync_canonical_inventory_after_machine_change();
create trigger machine_model_components_sync_canonical_inventory
after insert or update of is_active,component_id,machine_model_id,account_id on public.machine_model_components
for each row execute function public.sync_canonical_inventory_after_profile_change();
create trigger components_sync_canonical_inventory
after insert or update of is_active on public.components
for each row execute function public.sync_canonical_inventory_after_component_change();

do $$ declare account_record record; begin
  for account_record in select id from public.accounts loop
    perform public.provision_canonical_inventory_items_for_account(account_record.id);
  end loop;
end $$;

comment on column public.inventory_items.is_canonical is
  'Explicit default Inventory Item for one Component in one account; variants remain supported.';
comment on function public.sync_canonical_inventory_items(uuid) is
  'Idempotently adopts an unambiguous linked item or provisions a zero-stock canonical master. It creates no movement, purchase, receipt, or cost lot.';
