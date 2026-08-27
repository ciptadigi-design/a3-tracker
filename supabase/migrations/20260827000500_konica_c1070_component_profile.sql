-- Deterministic editable baseline profile for the existing C1070 model.

insert into public.components (id, code, name, category, manufacturer_id, default_tracking_method)
select
  ('53000000-0000-0000-0000-' || lpad(item.n::text, 12, '0'))::uuid,
  item.code, item.name, item.category,
  '50000000-0000-0000-0000-000000000001'::uuid,
  item.method::public.component_tracking_method
from (values
  (1,'CHARGING_CORONA_C','Charging Corona Cyan','Imaging','counter_based'),
  (2,'CHARGING_CORONA_M','Charging Corona Magenta','Imaging','counter_based'),
  (3,'CHARGING_CORONA_Y','Charging Corona Yellow','Imaging','counter_based'),
  (4,'CHARGING_CORONA_K','Charging Corona Black','Imaging','counter_based'),
  (5,'CLEANING_BLADE','Cleaning Blade','Cleaning','counter_based'),
  (6,'CLEANING_UNIT','Cleaning Unit','Cleaning','counter_based'),
  (7,'DEVELOPER_C','Developer Cyan','Imaging','counter_based'),
  (8,'DEVELOPER_M','Developer Magenta','Imaging','counter_based'),
  (9,'DEVELOPER_Y','Developer Yellow','Imaging','counter_based'),
  (10,'DEVELOPER_K','Developer Black','Imaging','counter_based'),
  (11,'DEVELOPING_UNIT_C','Developing Unit Cyan','Imaging','counter_based'),
  (12,'DEVELOPING_UNIT_M','Developing Unit Magenta','Imaging','counter_based'),
  (13,'DEVELOPING_UNIT_Y','Developing Unit Yellow','Imaging','counter_based'),
  (14,'DEVELOPING_UNIT_K','Developing Unit Black','Imaging','counter_based'),
  (15,'DRUM_C','Drum Unit Cyan','Imaging','counter_based'),
  (16,'DRUM_M','Drum Unit Magenta','Imaging','counter_based'),
  (17,'DRUM_Y','Drum Unit Yellow','Imaging','counter_based'),
  (18,'DRUM_K','Drum Unit Black','Imaging','counter_based'),
  (19,'FUSER_BELT','Fuser Belt','Fusing','counter_based'),
  (20,'GEAR','Gear','Mechanical','counter_based'),
  (21,'IBT','Intermediate Transfer Belt (IBT)','Transfer','counter_based'),
  (22,'LASER_UNIT','Laser Unit','Imaging','counter_based'),
  (23,'ROLL_MESIN','Roll Mesin','Paper Path','counter_based'),
  (24,'SENSOR','Sensor','Electrical','counter_based'),
  (25,'TONER_C','Toner Cyan','Consumable','consumption_based'),
  (26,'TONER_M','Toner Magenta','Consumable','consumption_based'),
  (27,'TONER_Y','Toner Yellow','Consumable','consumption_based'),
  (28,'TONER_K','Toner Black','Consumable','consumption_based')
) as item(n, code, name, category, method)
on conflict (id) do nothing;

insert into public.machine_model_components (
  id, machine_model_id, component_id, slot_code, display_order, tracking_method, baseline_expected_clicks
)
select
  ('54000000-0000-0000-0000-' || lpad(item.n::text, 12, '0'))::uuid,
  '51000000-0000-0000-0000-000000000001'::uuid,
  ('53000000-0000-0000-0000-' || lpad(item.component_n::text, 12, '0'))::uuid,
  item.slot_code,
  item.n,
  item.method::public.component_tracking_method,
  item.clicks
from (values
  (1,1,'CHARGING_CORONA_C','counter_based',40000::bigint),(2,2,'CHARGING_CORONA_M','counter_based',40000),(3,3,'CHARGING_CORONA_Y','counter_based',40000),(4,4,'CHARGING_CORONA_K','counter_based',40000),
  (5,5,'CLEANING_BLADE','counter_based',100000),(6,6,'CLEANING_UNIT','counter_based',200000),
  (7,7,'DEVELOPER_C','counter_based',100000),(8,8,'DEVELOPER_M','counter_based',100000),(9,9,'DEVELOPER_Y','counter_based',100000),(10,10,'DEVELOPER_K','counter_based',100000),
  (11,11,'DEVELOPING_UNIT_C','counter_based',200000),(12,12,'DEVELOPING_UNIT_M','counter_based',200000),(13,13,'DEVELOPING_UNIT_Y','counter_based',200000),(14,14,'DEVELOPING_UNIT_K','counter_based',200000),
  (15,15,'DRUM_C','counter_based',40000),(16,16,'DRUM_M','counter_based',40000),(17,17,'DRUM_Y','counter_based',40000),(18,18,'DRUM_K','counter_based',40000),
  (19,19,'FUSER_BELT','counter_based',200000),(20,20,'GEAR','counter_based',150000),(21,21,'IBT','counter_based',200000),(22,22,'LASER_UNIT','counter_based',300000),(23,23,'ROLL_MESIN','counter_based',150000),(24,24,'SENSOR','counter_based',250000),
  (25,25,'TONER_C','consumption_based',14000),(26,26,'TONER_M','consumption_based',14000),(27,27,'TONER_Y','consumption_based',14000),(28,28,'TONER_K','consumption_based',14000)
) as item(n, component_n, slot_code, method, clicks)
on conflict (id) do nothing;

-- The business list contains 28 named slots (24 mechanical + 4 toner).
-- This guard makes accidental seed drift fail loudly during migration/CI.
do $$
begin
  if (select count(*) from public.machine_model_components where account_id is null
      and machine_model_id = '51000000-0000-0000-0000-000000000001') <> 28 then
    raise exception 'C1070 baseline seed must contain exactly 28 supplied profiles';
  end if;
end $$;
