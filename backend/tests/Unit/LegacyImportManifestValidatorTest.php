<?php

namespace Tests\Unit;

use App\Services\LegacyImportManifestValidator;
use InvalidArgumentException;
use Tests\TestCase;

class LegacyImportManifestValidatorTest extends TestCase
{
    private function base(): array
    {
        return [
            'version'=>'m2.12e-neutral-import-v1','app_sha'=>str_repeat('a', 40),
            'source'=>['count'=>529,'fingerprints'=>[
                'click_history'=>'a577d071b32150c7ad13fd4b70c0fe6d9c4d3a7be08c27ad3efb8679cdeca537','part_replacements'=>'2dc08b4a2d2f6dcaf354732c44823485e895da922aa9583d7b5a2c8f4a2e207e','error_logs'=>'65d17c0c54b712ee04c5848e83147a2f892c1face3a48504a19e3d2a1e4cf35b','inventory_parts'=>'ffcd9463f006fc531987f7b3a17c0f1d3d9dd58a2c47ffef21252ef3347ca632','part_purchases'=>'6354204d285987b56764268ff22a06480003886528470401fdfe19766d7ee306']],
            'target'=>['account_id'=>'4b26a0ee-e06f-4563-a6cc-9dfc7fbc0e0c','branch_id'=>'94051ab9-235c-455f-b7ce-63f255cda3f6','machine_model_id'=>'f3b91ad2-7ecc-4d0c-a6ce-648c08691dab','machine_id'=>'708e199e-7f77-4219-b278-37d0b94821d4','counter_type_id'=>'00000000-0000-0000-0000-000000000001'],
            'disposition'=>['IMPORT'=>480,'MERGE'=>0,'SKIP_DUPLICATE'=>2,'ARCHIVE_ONLY'=>45,'APPROVED_EXCLUDE'=>2,'MANUAL_REVIEW'=>0],
            'masters'=>[],'records'=>[],'invariants'=>['planned_counter_readings'=>183,'latest_counter'=>1441597,'planned_component_lifecycles'=>47,'planned_purchases'=>161,'planned_purchase_units'=>208,'planned_purchase_total_idr'=>371029998,'planned_operational_incidents'=>89], 'crosswalk'=>[], 'safety'=>['legacy_receipts'=>0,'legacy_inventory_movements'=>0,'legacy_fifo_layers'=>0,'fake_stock'=>0,'unknown_cost_coerced_to_zero'=>0,'graha_leakage'=>0,'dev_target_uuid_leakage'=>0,'unresolved_mappings'=>0,'ijal_identity_fabricated'=>false],
        ];
    }

    public function test_source_count_is_fail_closed(): void
    {
        $m = $this->base(); $m['source']['count'] = 528;
        $this->expectException(InvalidArgumentException::class);
        (new LegacyImportManifestValidator)->validate($m);
    }

    public function test_fingerprint_and_disposition_are_fail_closed(): void
    {
        $m = $this->base(); $m['source']['fingerprints']['error_logs'] = 'bad';
        $this->expectException(InvalidArgumentException::class);
        (new LegacyImportManifestValidator)->validate($m);
    }

    public function test_manual_review_and_dev_target_are_rejected(): void
    {
        $m = $this->base(); $m['disposition']['MANUAL_REVIEW'] = 1;
        $this->expectException(InvalidArgumentException::class);
        (new LegacyImportManifestValidator)->validate($m);
    }

    public function test_lifecycle_counter_order_and_nulls_are_validated(): void
    {
        $m = $this->base();
        $m['records']['lifecycles'] = [['id'=>'11111111-1111-4111-8111-111111111111','machineComponentId'=>'22222222-2222-4222-8222-222222222222','installed'=>100,'removed'=>null]];
        (new LegacyImportManifestValidator)->validate($m);
        $m['records']['lifecycles'][0]['removed'] = 99;
        $this->expectException(InvalidArgumentException::class);
        (new LegacyImportManifestValidator)->validate($m);
    }
}
