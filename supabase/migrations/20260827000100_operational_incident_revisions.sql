-- A3 Tracker V2 - Product Patch M2.2.1
-- Append-only operational incident revisions and solved metadata.

alter table public.operational_incidents
  add column resolved_by uuid references auth.users(id) on delete restrict,
  add column resolved_at timestamptz,
  add column resolution_note text;

-- Preserve and enrich existing resolved rows without recreating incident data.
alter table public.operational_incidents disable trigger operational_incidents_protect_history;

update public.operational_incidents
set resolved_by = updated_by,
    resolved_at = updated_at
where status = 'resolved';

alter table public.operational_incidents enable trigger operational_incidents_protect_history;

alter table public.operational_incidents
  add constraint operational_incidents_resolution_note_not_blank check (
    resolution_note is null or btrim(resolution_note) <> ''
  );

create unique index operational_incidents_id_account_key
  on public.operational_incidents (id, account_id);

create table public.operational_incident_revisions (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  incident_id uuid not null,
  changed_by uuid not null references auth.users(id) on delete restrict,
  changed_at timestamptz not null default statement_timestamp(),
  change_reason text,
  old_values jsonb not null,
  new_values jsonb not null,
  changed_fields text[] not null,
  constraint operational_incident_revisions_incident_account_fkey
    foreign key (incident_id, account_id)
    references public.operational_incidents(id, account_id)
    on delete restrict,
  constraint operational_incident_revisions_reason_not_blank check (
    change_reason is null or btrim(change_reason) <> ''
  ),
  constraint operational_incident_revisions_snapshots_are_objects check (
    jsonb_typeof(old_values) = 'object'
    and jsonb_typeof(new_values) = 'object'
  ),
  constraint operational_incident_revisions_changed_fields_not_empty check (
    cardinality(changed_fields) > 0
  )
);

create index operational_incident_revisions_history_idx
  on public.operational_incident_revisions (account_id, incident_id, changed_at, id);

comment on table public.operational_incident_revisions is
  'Append-only, one-row-per-edit audit history for operational incidents.';

comment on column public.operational_incidents.resolution_note is
  'Optional team-review note recorded when an incident moves from open to resolved. UI label: Diselesaikan.';

-- Replace the M2.2 immutability trigger. Posted content remains immutable to
-- direct clients; the review RPC authorizes one open-record update by setting a
-- transaction-local incident id. Lifecycle transitions remain independently
-- controlled by their server-authorized RPCs.
create or replace function public.protect_operational_incident_history()
returns trigger
language plpgsql
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  authorized_edit_id text := current_setting('a3tracker.operational_incident_edit_id', true);
  editable_content_changed boolean;
begin
  if (to_jsonb(new) - array[
        'occurred_at', 'invoice_number', 'customer_name_snapshot',
        'product_name_snapshot', 'category', 'incident_type', 'machine_id',
        'qty_affected', 'responsible_user_id', 'responsible_name_snapshot',
        'material_loss', 'service_loss', 'description', 'cause', 'prevention',
        'customer_resolution', 'assessed_loss', 'status', 'updated_by',
        'updated_at', 'resolved_by', 'resolved_at', 'resolution_note',
        'voided_by', 'voided_at', 'void_reason'
      ]::text[])
      is distinct from
     (to_jsonb(old) - array[
        'occurred_at', 'invoice_number', 'customer_name_snapshot',
        'product_name_snapshot', 'category', 'incident_type', 'machine_id',
        'qty_affected', 'responsible_user_id', 'responsible_name_snapshot',
        'material_loss', 'service_loss', 'description', 'cause', 'prevention',
        'customer_resolution', 'assessed_loss', 'status', 'updated_by',
        'updated_at', 'resolved_by', 'resolved_at', 'resolution_note',
        'voided_by', 'voided_at', 'void_reason'
      ]::text[]) then
    raise exception 'protected operational incident fields are immutable'
      using errcode = '42501';
  end if;

  if old.status = 'voided' then
    raise exception 'voided operational incidents are immutable'
      using errcode = '42501';
  end if;

  editable_content_changed := (to_jsonb(new) - array[
      'id', 'account_id', 'branch_id', 'penalty_multiplier', 'assessed_loss',
      'client_request_id', 'created_by', 'created_at', 'status', 'updated_by',
      'updated_at', 'resolved_by', 'resolved_at', 'resolution_note',
      'voided_by', 'voided_at', 'void_reason'
    ]::text[])
    is distinct from
    (to_jsonb(old) - array[
      'id', 'account_id', 'branch_id', 'penalty_multiplier', 'assessed_loss',
      'client_request_id', 'created_by', 'created_at', 'status', 'updated_by',
      'updated_at', 'resolved_by', 'resolved_at', 'resolution_note',
      'voided_by', 'voided_at', 'void_reason'
    ]::text[]);

  if editable_content_changed then
    if old.status <> 'open' or new.status <> 'open' then
      raise exception 'only open operational incidents can be edited'
        using errcode = '42501';
    end if;

    if authorized_edit_id is distinct from old.id::text then
      raise exception 'posted operational incident content requires the review API'
        using errcode = '42501';
    end if;

    if new.resolved_by is distinct from old.resolved_by
      or new.resolved_at is distinct from old.resolved_at
      or new.resolution_note is distinct from old.resolution_note
      or new.voided_by is distinct from old.voided_by
      or new.voided_at is distinct from old.voided_at
      or new.void_reason is distinct from old.void_reason then
      raise exception 'lifecycle metadata cannot be changed during incident review'
        using errcode = '42501';
    end if;
  elsif new.status is distinct from old.status then
    if old.status = 'open' and new.status = 'resolved' then
      new.resolved_by := coalesce(actor_id, new.resolved_by);
      new.resolved_at := statement_timestamp();
      new.resolution_note := nullif(btrim(new.resolution_note), '');
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
  else
    raise exception 'operational incident update requires an edit or lifecycle transition'
      using errcode = '42501';
  end if;

  new.updated_by := coalesce(actor_id, new.updated_by, old.updated_by);
  new.updated_at := statement_timestamp();
  return new;
end;
$$;

revoke all on table public.operational_incident_revisions
  from public, anon, authenticated, service_role;

revoke all on function public.protect_operational_incident_history()
  from public, anon, authenticated, service_role;
