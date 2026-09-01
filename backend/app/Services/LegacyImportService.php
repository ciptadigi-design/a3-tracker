<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class LegacyImportService
{
    public function __construct(private readonly LegacyImportManifestValidator $validator) {}

    public function apply(array $manifest, bool $dryRun = true, ?string $injectFailure = null): array
    {
        $m = $this->validator->validate($manifest);
        // Refuse to run if a deployment has not applied the forward schema
        // migration; never collapse counters into notes.
        foreach (['installed_counter', 'removed_counter'] as $column) {
            if (! Schema::hasColumn('component_lifecycles', $column)) {
                throw new RuntimeException("Schema parity blocker: component_lifecycles.{$column} is missing");
            }
        }
        $writes = 0;
        DB::beginTransaction();
        try {
            $target = $m['target'];
            foreach ($m['masters']['operational_people'] as $row) $writes += $this->upsertExact('operational_people', $row['id'], ['account_id'=>$target['account_id'],'name'=>$row['name'],'linked_user_id'=>null,'is_active'=>true,'notes'=>'LEGACY_IMPORT; historical person only; no Auth identity']);
            foreach ($m['masters']['operational_person_branches'] as $row) $writes += $this->upsertExact('operational_person_branches', $row['id'], ['account_id'=>$target['account_id'],'person_id'=>$row['person_id'],'branch_id'=>$target['branch_id'],'is_active'=>true,'can_record_counter'=>true]);
            foreach ($m['masters']['component_catalogs'] as $row) $writes += $this->upsertExact('component_catalogs', $row['id'], ['account_id'=>$target['account_id'],'code'=>$row['code'],'name'=>$row['code'],'tracking_method'=>'counter_based','is_active'=>true]);
            foreach ($m['masters']['model_profile'] as $row) $writes += $this->upsertExact('model_profiles', $row['id'], ['account_id'=>null,'machine_model_id'=>$target['machine_model_id'],'name'=>$row['name'],'is_active'=>true]);
            foreach ($m['masters']['model_profile_slots'] as $row) $writes += $this->upsertExact('model_profile_slots', $row['id'], ['profile_id'=>$row['profile_id'],'component_id'=>$row['component_id'],'slot_code'=>$row['slot_code'],'display_order'=>$row['display_order'],'tracking_method'=>'counter_based','is_active'=>true]);
            foreach ($m['masters']['machine_components'] as $row) $writes += $this->upsertExact('machine_components', $row['id'], ['account_id'=>$target['account_id'],'machine_id'=>$target['machine_id'],'component_id'=>$row['component_id'],'profile_slot_id'=>$row['profile_slot_id'],'slot_code'=>$row['slot_code'],'source_type'=>'configured','status'=>'configured','active_key'=>'active','display_order'=>$row['display_order'] ?? 0]);
            foreach ($m['masters']['suppliers'] as $row) $writes += $this->upsertExact('inventory_suppliers', $row['id'], ['account_id'=>$target['account_id'],'code'=>$row['code'],'name'=>$row['name'],'notes'=>'LEGACY_IMPORT supplier snapshot','is_active'=>true]);
            foreach ($m['masters']['inventory_items'] as $row) $writes += $this->upsertExact('inventory_items', $row['id'], ['account_id'=>$target['account_id'],'component_id'=>$row['componentId'],'sku'=>$row['sku'],'name'=>$row['name'],'unit'=>'pcs','is_active'=>true]);
            foreach ($m['records']['counters'] as $row) $writes += $this->upsertExact('counter_readings', $row['id'], ['account_id'=>$target['account_id'],'machine_id'=>$target['machine_id'],'counter_type_id'=>$target['counter_type_id'],'reading_value'=>$row['value'],'observed_at'=>$this->mysqlTimestamp($row['observed_at']),'operator_person_id'=>$row['operator']['id'] ?? null,'operator_name_snapshot'=>$row['rawOperator'] ?? null,'source'=>'legacy_import','previous_reading_id'=>$row['previousId'],'status'=>'effective','notes'=>'LEGACY_IMPORT source='.$row['sourceId'].'; '.$row['evidence'],'client_request_id'=>$row['id']]);
            foreach ($m['records']['lifecycles'] as $row) $writes += $this->upsertExact('component_lifecycles', $row['id'], ['machine_component_id'=>$row['machineComponentId'],'installed_counter'=>$row['installed'],'removed_counter'=>$row['removed'],'actual_usage'=>$row['removed'] !== null && $row['installed'] !== null ? $row['removed'] - $row['installed'] : null,'started_at'=>null,'ended_at'=>null,'status'=>'closed','evidence_level'=>'S','source'=>'legacy_import','notes'=>'LEGACY_IMPORT source='.$row['sourceId'],'client_request_id'=>$row['id'],'active_key'=>null]);
            foreach ($m['records']['purchases'] as $row) {
                $writes += $this->upsertExact('purchases', $row['id'], ['account_id'=>$target['account_id'],'purchase_number'=>'LEGACY-'.$row['sourceId'],'purchase_date'=>$row['date'],'currency_code'=>'IDR','status'=>'draft','notes'=>'LEGACY_IMPORT; RECEIPT_UNKNOWN_NOT_REPRESENTED; source_id='.$row['sourceId'],'client_request_id'=>$row['requestId']]);
                $writes += $this->upsertExact('purchase_lines', $row['lineId'], ['account_id'=>$target['account_id'],'purchase_id'=>$row['id'],'inventory_item_id'=>$row['item']['id'],'ordered_quantity'=>$row['qty'],'unit_cost'=>$row['unitPrice'],'notes'=>'LEGACY_IMPORT source_total='.$row['sourceTotal']]);
            }
            foreach ($m['records']['incidents'] as $row) $writes += $this->upsertExact('operational_incidents', $row['id'], ['account_id'=>$target['account_id'],'branch_id'=>$target['branch_id'],'machine_id'=>$target['machine_id'],'occurred_at'=>$this->mysqlTimestamp($row['occurredAt']),'invoice_number'=>$row['invoice'],'customer_name_snapshot'=>$row['customer'],'product_name_snapshot'=>$row['product'],'category'=>$row['category'],'incident_type'=>$row['type'],'qty_affected'=>$row['qty'],'operator_person_id'=>$row['person']['id'] ?? null,'operator_name_snapshot'=>$row['rawPic'] ?? null,'responsible_person_id'=>$row['person']['id'] ?? null,'responsible_name_snapshot'=>$row['rawPic'] ?? null,'material_loss'=>$row['material'],'service_loss'=>$row['service'],'base_amount'=>$row['material']+$row['service'],'penalty_multiplier'=>$row['multiplier'],'assessed_loss'=>$row['stored'],'description'=>$row['description'],'cause'=>$row['cause'],'prevention'=>$row['prevention'],'customer_resolution'=>$row['resolution'],'status'=>'open','client_request_id'=>$row['requestId'],'created_at'=>$this->mysqlTimestamp($row['createdAt']),'updated_at'=>$this->mysqlTimestamp($row['createdAt'])]);
            if ($injectFailure !== null) throw new RuntimeException('deterministic failure injection: '.$injectFailure);
            if ($dryRun) DB::rollBack(); else DB::commit();
            return ['new_writes'=>$dryRun ? 0 : $writes, 'would_write'=>$writes, 'rolled_back'=>$dryRun];
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            throw $e;
        }
    }

    private function mysqlTimestamp(string $value): string
    {
        return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
    }

    private function upsertExact(string $table, string $id, array $values): int
    {
        $existing = DB::table($table)->where('id', $id)->first();
        if ($existing) {
            foreach ($values as $key => $value) {
                $old = $existing->{$key} ?? null;
                $equal = is_numeric($old) && is_numeric($value) ? abs((float) $old - (float) $value) < 0.00005 : (string) $old === (string) $value;
                if (! $equal) throw new RuntimeException("Conflicting existing {$table}: {$id}");
            }
            return 0;
        }
        $now = now(); $values['id'] = $id; $values['created_at'] ??= $now; $values['updated_at'] ??= $now;
        DB::table($table)->insert($values); return 1;
    }
}
