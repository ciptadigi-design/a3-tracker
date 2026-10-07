<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ComponentCatalog;
use App\Models\InventoryComponentCompatibility;
use App\Models\InventoryItem;
use App\Services\InventoryComponentCompatibilityBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 transitional backfill: (a) transcribe every existing non-null
 * inventory_items.component_id into an explicit compatibility row, and (b)
 * resolve the real "Charging Corona" generic item + its four real CMYK
 * component_catalogs rows BY NAME (never a guessed/hardcoded id), per
 * account, failing closed on any ambiguity.
 */
class InventoryComponentCompatibilityBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $code = 'CG'): Account
    {
        return Account::create(['code' => $code, 'name' => $code]);
    }

    public function test_generic_backfill_creates_one_row_per_existing_non_null_component_link(): void
    {
        $a = $this->account();
        $drum = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'drum', 'name' => 'Drum', 'is_active' => true]);
        $item = InventoryItem::create(['account_id' => $a->id, 'component_id' => $drum->id, 'sku' => 'DRUM-01', 'name' => 'Drum', 'is_active' => true]);

        $plan = app(InventoryComponentCompatibilityBackfillService::class)->run(true);

        $this->assertCount(1, $plan['generic_backfill']);
        $this->assertSame(1, InventoryComponentCompatibility::where('inventory_item_id', $item->id)->where('component_id', $drum->id)->where('is_active', true)->count());
    }

    public function test_generic_backfill_is_idempotent_on_rerun(): void
    {
        $a = $this->account();
        $drum = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'drum', 'name' => 'Drum', 'is_active' => true]);
        InventoryItem::create(['account_id' => $a->id, 'component_id' => $drum->id, 'sku' => 'DRUM-01', 'name' => 'Drum', 'is_active' => true]);

        app(InventoryComponentCompatibilityBackfillService::class)->run(true);
        $plan = app(InventoryComponentCompatibilityBackfillService::class)->run(true);

        $this->assertCount(0, $plan['generic_backfill'], 'a second run must plan nothing new');
        $this->assertSame(1, InventoryComponentCompatibility::count(), 'no duplicate row was created');
    }

    public function test_dry_run_plans_but_never_commits(): void
    {
        $a = $this->account();
        $drum = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'drum', 'name' => 'Drum', 'is_active' => true]);
        InventoryItem::create(['account_id' => $a->id, 'component_id' => $drum->id, 'sku' => 'DRUM-01', 'name' => 'Drum', 'is_active' => true]);

        $plan = app(InventoryComponentCompatibilityBackfillService::class)->run(false);

        $this->assertCount(1, $plan['generic_backfill']);
        $this->assertSame(0, InventoryComponentCompatibility::count(), 'a dry run must not persist anything');
    }

    private function chargingCoronaFixture(Account $a): array
    {
        $k = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'corona_k', 'name' => 'Corona K Def', 'is_active' => true]);
        $c = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'corona_c', 'name' => 'Corona C Def', 'is_active' => true]);
        $m = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'corona_m', 'name' => 'Corona M Def', 'is_active' => true]);
        $y = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'corona_y', 'name' => 'Corona Y Def', 'is_active' => true]);
        $generic = InventoryItem::create(['account_id' => $a->id, 'component_id' => null, 'sku' => 'GEN-CORONA', 'name' => 'Charging Corona', 'is_active' => true]);
        InventoryItem::create(['account_id' => $a->id, 'component_id' => $k->id, 'sku' => 'CORONA-K', 'name' => 'Charging Corona Black', 'is_active' => true]);
        InventoryItem::create(['account_id' => $a->id, 'component_id' => $c->id, 'sku' => 'CORONA-C', 'name' => 'Charging Corona Cyan', 'is_active' => true]);
        InventoryItem::create(['account_id' => $a->id, 'component_id' => $m->id, 'sku' => 'CORONA-M', 'name' => 'Charging Corona Magenta', 'is_active' => true]);
        InventoryItem::create(['account_id' => $a->id, 'component_id' => $y->id, 'sku' => 'CORONA-Y', 'name' => 'Charging Corona Yellow', 'is_active' => true]);

        return compact('k', 'c', 'm', 'y', 'generic');
    }

    public function test_charging_corona_resolves_by_name_to_the_four_real_catalog_rows(): void
    {
        $a = $this->account();
        $f = $this->chargingCoronaFixture($a);

        $plan = app(InventoryComponentCompatibilityBackfillService::class)->run(true);

        $this->assertCount(4, $plan['generic_color_families']['created']);
        $this->assertCount(0, $plan['generic_color_families']['skipped']);
        foreach ([$f['k'], $f['c'], $f['m'], $f['y']] as $component) {
            $this->assertSame(1, InventoryComponentCompatibility::where('inventory_item_id', $f['generic']->id)->where('component_id', $component->id)->where('is_active', true)->count(), "missing mapping for {$component->name}");
        }
    }

    public function test_charging_corona_mapping_is_idempotent_on_rerun(): void
    {
        $a = $this->account();
        $this->chargingCoronaFixture($a);

        app(InventoryComponentCompatibilityBackfillService::class)->run(true);
        $plan = app(InventoryComponentCompatibilityBackfillService::class)->run(true);

        $this->assertCount(0, $plan['generic_color_families']['created'], 'a second run must create nothing new');
        // 4 Charging Corona (generic -> CMYK) rows + 4 generic-backfill rows for
        // the color-specific items themselves (each already has a non-null
        // component_id, so step A links each of them too) = 8.
        $this->assertSame(8, InventoryComponentCompatibility::count());
    }

    public function test_ambiguous_generic_item_is_skipped_not_guessed(): void
    {
        $a = $this->account();
        $f = $this->chargingCoronaFixture($a);
        InventoryItem::create(['account_id' => $a->id, 'component_id' => null, 'sku' => 'GEN-CORONA-2', 'name' => 'Charging Corona', 'is_active' => true]);

        $plan = app(InventoryComponentCompatibilityBackfillService::class)->run(true);

        $this->assertCount(0, $plan['generic_color_families']['created']);
        $this->assertCount(2, $plan['generic_color_families']['skipped'], 'both items sharing the ambiguous name are reported, not just one');
        $this->assertStringContainsString('ambiguous', $plan['generic_color_families']['skipped'][0]['reason']);
        $this->assertSame(0, InventoryComponentCompatibility::where('inventory_item_id', $f['generic']->id)->count());
    }

    public function test_ambiguous_color_specific_item_is_skipped_not_guessed(): void
    {
        $a = $this->account();
        $f = $this->chargingCoronaFixture($a);
        $k2 = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'corona_k2', 'name' => 'Corona K Def 2', 'is_active' => true]);
        InventoryItem::create(['account_id' => $a->id, 'component_id' => $k2->id, 'sku' => 'CORONA-K-2', 'name' => 'Charging Corona Black', 'is_active' => true]);

        $plan = app(InventoryComponentCompatibilityBackfillService::class)->run(true);

        $this->assertCount(0, $plan['generic_color_families']['created'], 'no partial CMYK mapping is created when any one color is ambiguous');
        $this->assertCount(1, $plan['generic_color_families']['skipped']);
        $this->assertStringContainsString('ambiguous', $plan['generic_color_families']['skipped'][0]['reason']);
        $this->assertSame(0, InventoryComponentCompatibility::where('inventory_item_id', $f['generic']->id)->count());
    }

    public function test_charging_corona_mapping_is_per_account_and_does_not_cross_tenants(): void
    {
        $a1 = $this->account('CG');
        $a2 = $this->account('OTHER');
        $f1 = $this->chargingCoronaFixture($a1);
        $f2 = $this->chargingCoronaFixture($a2);

        $plan = app(InventoryComponentCompatibilityBackfillService::class)->run(true);

        $this->assertCount(8, $plan['generic_color_families']['created']);
        $this->assertSame(4, InventoryComponentCompatibility::where('inventory_item_id', $f1['generic']->id)->count());
        $this->assertSame(4, InventoryComponentCompatibility::where('inventory_item_id', $f2['generic']->id)->count());
        foreach (InventoryComponentCompatibility::where('inventory_item_id', $f1['generic']->id)->get() as $row) {
            $this->assertSame((string) $a1->id, $row->account_id);
        }
    }

    public function test_charging_corona_mapping_never_changes_inventory_balances(): void
    {
        $a = $this->account();
        $f = $this->chargingCoronaFixture($a);
        $loc = \App\Models\InventoryLocation::create(['account_id' => $a->id, 'branch_id' => \App\Models\Branch::create(['account_id' => $a->id, 'code' => 'MAIN', 'name' => 'Main'])->id, 'code' => 'WH', 'name' => 'Warehouse']);
        app(\App\Services\InventoryLedgerService::class)->inbound($f['generic'], $loc, 2, 1000, 'opening_balance', (string) \Illuminate\Support\Str::uuid(), 'opening');

        app(InventoryComponentCompatibilityBackfillService::class)->run(true);

        $this->assertSame(2.0, app(\App\Services\InventoryLedgerService::class)->balance($f['generic']->id, $loc->id));
        $this->assertSame(1, \App\Models\InventoryMovement::count(), 'the backfill must create no inventory movement');
    }

    // The resolver is family-name-agnostic: an entirely different generic
    // family ("Drum Unit", not "Charging Corona") resolves the exact same
    // way, by name, with zero family-specific code anywhere in the service.
    public function test_a_different_generic_family_resolves_by_name_with_no_hardcoded_family_name(): void
    {
        $a = $this->account();
        $k = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'drum_k', 'name' => 'DRUM_K', 'is_active' => true]);
        $c = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'drum_c', 'name' => 'DRUM_C', 'is_active' => true]);
        $m = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'drum_m', 'name' => 'DRUM_M', 'is_active' => true]);
        $y = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'drum_y', 'name' => 'DRUM_Y', 'is_active' => true]);
        $generic = InventoryItem::create(['account_id' => $a->id, 'component_id' => null, 'sku' => 'DRUM-GEN', 'name' => 'Drum Unit', 'is_active' => true]);
        InventoryItem::create(['account_id' => $a->id, 'component_id' => $k->id, 'sku' => 'DRUM-K', 'name' => 'Drum Unit Black', 'is_active' => true]);
        InventoryItem::create(['account_id' => $a->id, 'component_id' => $c->id, 'sku' => 'DRUM-C', 'name' => 'Drum Unit Cyan', 'is_active' => true]);
        InventoryItem::create(['account_id' => $a->id, 'component_id' => $m->id, 'sku' => 'DRUM-M', 'name' => 'Drum Unit Magenta', 'is_active' => true]);
        InventoryItem::create(['account_id' => $a->id, 'component_id' => $y->id, 'sku' => 'DRUM-Y', 'name' => 'Drum Unit Yellow', 'is_active' => true]);

        $plan = app(InventoryComponentCompatibilityBackfillService::class)->run(true);

        $this->assertCount(4, $plan['generic_color_families']['created']);
        $this->assertCount(0, $plan['generic_color_families']['skipped']);
        foreach ([$k, $c, $m, $y] as $component) {
            $this->assertSame(1, InventoryComponentCompatibility::where('inventory_item_id', $generic->id)->where('component_id', $component->id)->where('is_active', true)->count(), "missing mapping for {$component->name}");
        }
    }

    // A generic item with no four-color sibling set at all (a true
    // miscellaneous bucket, e.g. "Other Part") is skipped, never guessed.
    public function test_a_generic_item_with_no_color_siblings_at_all_is_skipped(): void
    {
        $a = $this->account();
        $misc = InventoryItem::create(['account_id' => $a->id, 'component_id' => null, 'sku' => 'MISC-01', 'name' => 'Other Part', 'is_active' => true]);

        $plan = app(InventoryComponentCompatibilityBackfillService::class)->run(true);

        $this->assertCount(0, $plan['generic_color_families']['created']);
        $this->assertCount(1, $plan['generic_color_families']['skipped']);
        $this->assertSame((string) $misc->id, $plan['generic_color_families']['skipped'][0]['inventory_item_id']);
        $this->assertStringContainsString('ambiguous', $plan['generic_color_families']['skipped'][0]['reason']);
        $this->assertSame(0, InventoryComponentCompatibility::where('inventory_item_id', $misc->id)->count());
    }

    // Two unrelated generic families in the same account (e.g. Charging
    // Corona and Drum Unit) each resolve correctly without cross-talk.
    public function test_two_unrelated_generic_families_in_the_same_account_do_not_cross_contaminate(): void
    {
        $a = $this->account();
        $corona = $this->chargingCoronaFixture($a);
        $k = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'drum_k', 'name' => 'DRUM_K', 'is_active' => true]);
        $drumGeneric = InventoryItem::create(['account_id' => $a->id, 'component_id' => null, 'sku' => 'DRUM-GEN', 'name' => 'Drum Unit', 'is_active' => true]);
        InventoryItem::create(['account_id' => $a->id, 'component_id' => $k->id, 'sku' => 'DRUM-K', 'name' => 'Drum Unit Black', 'is_active' => true]);
        // Only one of Drum Unit's four colors exists -> Drum Unit must be skipped, Charging Corona must still fully resolve.

        $plan = app(InventoryComponentCompatibilityBackfillService::class)->run(true);

        $this->assertSame(4, InventoryComponentCompatibility::where('inventory_item_id', $corona['generic']->id)->count());
        $this->assertSame(0, InventoryComponentCompatibility::where('inventory_item_id', $drumGeneric->id)->count());
        $this->assertTrue(collect($plan['generic_color_families']['skipped'])->contains(fn ($row) => $row['inventory_item_id'] === (string) $drumGeneric->id));
    }
}
