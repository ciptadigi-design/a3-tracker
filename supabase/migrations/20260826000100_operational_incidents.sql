-- A3 Tracker V2 - Product Milestone M2.2
-- Operational / human error incidents. This domain deliberately excludes
-- machine technical fault codes and maintenance diagnostics.

create type public.operational_incident_category as enum (
  'kesesuaian',
  'kualitas',
  'desain',
  'bahan',
  'prosedur'
);

create type public.operational_incident_type as enum (
  'machine_operation',
  'human',
  'test_print'
);

create type public.operational_incident_status as enum (
  'open',
  'resolved',
  'voided'
);

-- Supports a branch-safe optional machine reference. A machine selected for
-- an incident must belong to both the same account and the same branch.
create unique index machines_id_account_branch_key
  on public.machines (id, account_id, branch_id);

create table public.operational_incidents (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  branch_id uuid not null,
  machine_id uuid,
  occurred_at timestamptz not null,
  invoice_number text,
  customer_name_snapshot text,
  product_name_snapshot text,
  category public.operational_incident_category not null,
  incident_type public.operational_incident_type not null,
  qty_affected integer,
  responsible_user_id uuid,
  responsible_name_snapshot text,
  material_loss numeric(18, 2) not null default 0,
  service_loss numeric(18, 2) not null default 0,
  penalty_multiplier numeric(8, 4) not null default 1,
  assessed_loss numeric(20, 2) generated always as (
    (material_loss + service_loss) * penalty_multiplier
  ) stored,
  description text not null,
  cause text,
  prevention text,
  customer_resolution text,
  status public.operational_incident_status not null default 'open',
  client_request_id uuid not null,
  created_by uuid not null default auth.uid() references auth.users(id) on delete restrict,
  created_at timestamptz not null default statement_timestamp(),
  updated_by uuid not null default auth.uid() references auth.users(id) on delete restrict,
  updated_at timestamptz not null default statement_timestamp(),
  voided_by uuid references auth.users(id) on delete restrict,
  voided_at timestamptz,
  void_reason text,
  constraint operational_incidents_branch_account_fkey
    foreign key (branch_id, account_id)
    references public.branches(id, account_id)
    on delete restrict,
  constraint operational_incidents_machine_scope_fkey
    foreign key (machine_id, account_id, branch_id)
    references public.machines(id, account_id, branch_id)
    on delete restrict,
  constraint operational_incidents_responsible_membership_fkey
    foreign key (account_id, responsible_user_id)
    references public.account_memberships(account_id, user_id)
    on delete set null (responsible_user_id),
  constraint operational_incidents_qty_positive check (
    qty_affected is null or qty_affected > 0
  ),
  constraint operational_incidents_material_loss_nonnegative check (material_loss >= 0),
  constraint operational_incidents_service_loss_nonnegative check (service_loss >= 0),
  constraint operational_incidents_penalty_multiplier_valid check (penalty_multiplier >= 1),
  constraint operational_incidents_description_not_blank check (btrim(description) <> ''),
  constraint operational_incidents_optional_text_not_blank check (
    (invoice_number is null or btrim(invoice_number) <> '')
    and (customer_name_snapshot is null or btrim(customer_name_snapshot) <> '')
    and (product_name_snapshot is null or btrim(product_name_snapshot) <> '')
    and (responsible_name_snapshot is null or btrim(responsible_name_snapshot) <> '')
    and (cause is null or btrim(cause) <> '')
    and (prevention is null or btrim(prevention) <> '')
    and (customer_resolution is null or btrim(customer_resolution) <> '')
  ),
  constraint operational_incidents_void_fields_consistent check (
    (
      status = 'voided'
      and voided_by is not null
      and voided_at is not null
      and nullif(btrim(void_reason), '') is not null
    )
    or (
      status <> 'voided'
      and voided_by is null
      and voided_at is null
      and void_reason is null
    )
  )
);

create unique index operational_incidents_account_client_request_key
  on public.operational_incidents (account_id, client_request_id);

create index operational_incidents_branch_history_idx
  on public.operational_incidents (account_id, branch_id, occurred_at desc, created_at desc);

create index operational_incidents_machine_history_idx
  on public.operational_incidents (account_id, machine_id, occurred_at desc)
  where machine_id is not null;

create index operational_incidents_status_idx
  on public.operational_incidents (account_id, branch_id, status, occurred_at desc);

create or replace function public.protect_operational_incident_history()
returns trigger
language plpgsql
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
begin
  if (to_jsonb(new) - 'assessed_loss' - 'status' - 'updated_by' - 'updated_at'
      - 'voided_by' - 'voided_at' - 'void_reason')
      is distinct from
     (to_jsonb(old) - 'assessed_loss' - 'status' - 'updated_by' - 'updated_at'
      - 'voided_by' - 'voided_at' - 'void_reason') then
    raise exception 'posted operational incident content is immutable'
      using errcode = '42501';
  end if;

  if old.status = 'voided' then
    raise exception 'voided operational incidents are immutable'
      using errcode = '42501';
  end if;

  if new.status = old.status then
    raise exception 'operational incident update requires a lifecycle transition'
      using errcode = '42501';
  end if;

  if old.status = 'open' and new.status = 'resolved' then
    new.voided_by := null;
    new.voided_at := null;
    new.void_reason := null;
  elsif old.status in ('open', 'resolved') and new.status = 'voided' then
    if nullif(btrim(new.void_reason), '') is null then
      raise exception 'void reason is required' using errcode = '22023';
    end if;
    new.voided_by := coalesce(actor_id, new.voided_by);
    new.voided_at := statement_timestamp();
    new.void_reason := btrim(new.void_reason);
  else
    raise exception 'invalid operational incident lifecycle transition'
      using errcode = '42501';
  end if;

  new.updated_by := coalesce(actor_id, new.updated_by, old.updated_by);
  new.updated_at := statement_timestamp();
  return new;
end;
$$;

revoke all on function public.protect_operational_incident_history() from public;

create trigger operational_incidents_protect_history
before update on public.operational_incidents
for each row execute function public.protect_operational_incident_history();

comment on table public.operational_incidents is
  'Operational and human production errors only. incident_type machine_operation is not a technical machine fault code.';
