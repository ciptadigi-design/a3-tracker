-- DEV-ONLY, GUARDED M2.3E OPERATIONAL PEOPLE BOOTSTRAP.
-- Target project must be independently confirmed as sxitqjxljoqsnpepymrl.
-- This file is not a migration and is never loaded by seed.sql/db reset.

begin;

do $$
declare
  target_account_id uuid;
  matched_count integer;
begin
  select machine.account_id into strict target_account_id
  from public.machines machine
  join public.accounts account on account.id = machine.account_id
  where machine.machine_code = 'CG-TUP-A3-01'
    and account.name = 'Cipta Grafika';

  insert into public.operational_people (account_id, name, is_active, notes)
  values
    (target_account_id, 'Muhammad Angga Nugraha', true, 'Initial Cipta Grafika operational directory bootstrap.'),
    (target_account_id, 'Muhammad Daffa Ramadhiansyah', true, 'Initial Cipta Grafika operational directory bootstrap.'),
    (target_account_id, 'Akmal Fauzan', true, 'Initial Cipta Grafika operational directory bootstrap.')
  on conflict (account_id, (lower(btrim(name)))) do update
    set is_active = true;

  select count(*) into matched_count
  from public.operational_people person
  where person.account_id = target_account_id
    and person.is_active
    and person.name in (
      'Muhammad Angga Nugraha',
      'Muhammad Daffa Ramadhiansyah',
      'Akmal Fauzan'
    );

  if matched_count <> 3 then
    raise exception 'operator bootstrap verification failed: expected 3 active records, found %', matched_count;
  end if;

  raise notice 'M2.3E bootstrap verified 3 active Cipta Grafika operational people';
end;
$$;

commit;
