-- Shared, environment-independent machine master catalog.
-- Fixed UUIDs make this seed repeatable without relying on tenant data.
insert into public.manufacturers (id, code, name)
values (
  '50000000-0000-0000-0000-000000000001',
  'KONICA_MINOLTA',
  'Konica Minolta'
)
on conflict (id) do nothing;

insert into public.machine_models (
  id,
  manufacturer_id,
  model_code,
  name,
  machine_category,
  color_capability
)
values (
  '51000000-0000-0000-0000-000000000001',
  '50000000-0000-0000-0000-000000000001',
  'ACCURIOPRESS_C1070',
  'AccurioPress C1070',
  'digital_a3',
  'color'
)
on conflict (id) do nothing;

insert into public.counter_types (
  id,
  code,
  name,
  unit,
  decimal_scale,
  is_monotonic,
  description
)
values
  (
    '52000000-0000-0000-0000-000000000001',
    'total_impressions',
    'Total Impressions',
    'impressions',
    0,
    true,
    'Total lifetime impressions produced by the machine.'
  ),
  (
    '52000000-0000-0000-0000-000000000002',
    'color_impressions',
    'Color Impressions',
    'impressions',
    0,
    true,
    'Lifetime color impressions produced by the machine.'
  ),
  (
    '52000000-0000-0000-0000-000000000003',
    'bw_impressions',
    'Black and White Impressions',
    'impressions',
    0,
    true,
    'Lifetime monochrome impressions produced by the machine.'
  ),
  (
    '52000000-0000-0000-0000-000000000004',
    'operating_hours',
    'Operating Hours',
    'hours',
    2,
    true,
    'Total lifetime operating hours recorded by the machine.'
  )
on conflict (id) do nothing;

-- Tenant data remains intentionally absent. The first account owner must be a
-- real Supabase Auth user; see bootstrap/dev_first_account.sql.example for the
-- guarded, manual DEV-only bootstrap. No physical machine is created here.
