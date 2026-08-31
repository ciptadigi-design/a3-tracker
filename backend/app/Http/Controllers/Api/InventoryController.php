<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventorySupplier;
use App\Models\MachineComponent;
use App\Services\AccountAccessResolver;
use App\Services\BranchAccessResolver;
use App\Services\InventoryLedgerService;
use App\Services\MachineAccessResolver;
use App\Services\PurchaseReceiptService;
use App\Services\ReplaceMachineComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function workspace(Request $r, string $account, string $branch)
    {
        $a = Account::findOrFail($account);
        abort_unless(app(AccountAccessResolver::class)->canAccess($r->user(), $a), 403);
        abort_unless(app(BranchAccessResolver::class)->canAccess($r->user(), Branch::findOrFail($branch)), 403);
        $items = InventoryItem::where('account_id', $account)->where('is_active', true)->with('component')->orderBy('name')->get();
        $locations = InventoryLocation::where('account_id', $account)->where('branch_id', $branch)->where('is_active', true)->orderBy('name')->get();
        $ledger = app(InventoryLedgerService::class);
        $balances = $locations->flatMap(fn ($l) => $items->map(fn ($i) => ['account_id' => $account, 'inventory_item_id' => $i->id, 'location_id' => $l->id, 'quantity' => $ledger->balance($i->id, $l->id)]))->values();
        $purchases = DB::table('purchases')->where('account_id', $account)->orderByDesc('purchase_date')->get();
        $purchaseIds = $purchases->pluck('id');

        return response()->json(['data' => ['branchId' => $branch, 'items' => $items, 'locations' => $locations, 'suppliers' => InventorySupplier::where('account_id', $account)->where('is_active', true)->orderBy('name')->get(), 'balances' => $balances, 'totals' => $items->map(fn ($i) => ['account_id' => $account, 'inventory_item_id' => $i->id, 'quantity' => $balances->where('inventory_item_id', $i->id)->sum('quantity')])->values(), 'movements' => DB::table('inventory_movements')->where('account_id', $account)->whereIn('location_id', $locations->pluck('id'))->orderByDesc('occurred_at')->limit(500)->get(), 'components' => DB::table('components')->where('is_active', true)->orderBy('name')->get(), 'people' => DB::table('operational_people')->where('account_id', $account)->where('is_active', true)->orderBy('name')->get(), 'purchases' => $purchases, 'purchaseLines' => $purchaseIds->isEmpty() ? collect() : DB::table('purchase_lines')->whereIn('purchase_id', $purchaseIds)->get(), 'receipts' => $purchaseIds->isEmpty() ? collect() : DB::table('receipts')->whereIn('purchase_id', $purchaseIds)->get(), 'lastPrices' => [], 'costHistory' => [], 'costPositions' => []]]);
    }

    public function suppliers(Request $r)
    {
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');

        return response()->json(['data' => InventorySupplier::whereIn('account_id', $ids)->orderBy('name')->get()]);
    }

    public function saveSupplier(Request $r, ?string $id = null)
    {
        $d = $r->validate(['account_id' => 'required|uuid', 'code' => 'required|string|max:80', 'name' => 'required|string|max:160', 'contact_name' => 'nullable|string', 'phone' => 'nullable|string', 'email' => 'nullable|email', 'address' => 'nullable|string', 'notes' => 'nullable|string', 'is_active' => 'boolean']);
        $a = Account::findOrFail($d['account_id']);
        abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $a), 403);
        $s = $id ? InventorySupplier::where('account_id', $a->id)->findOrFail($id) : new InventorySupplier(['account_id' => $a->id]);
        $s->fill($d);
        $s->save();

        return response()->json(['data' => $s]);
    }

    public function deleteSupplier(Request $r, string $id)
    {
        $s = InventorySupplier::findOrFail($id);
        $a = Account::findOrFail($s->account_id);
        abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $a), 403);
        $s->delete();

        return response()->noContent();
    }

    public function saveItem(Request $r, ?string $id = null)
    {
        $d = $r->validate(['account_id' => 'required|uuid', 'component_id' => 'nullable|uuid', 'sku' => 'nullable|string|max:80', 'name' => 'required|string|max:160', 'category' => 'nullable|string|max:80', 'unit' => 'required|string|max:20', 'minimum_stock' => 'nullable|numeric', 'is_active' => 'boolean']);
        $a = Account::findOrFail($d['account_id']);
        abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $a), 403);
        $i = $id ? InventoryItem::where('account_id', $a->id)->findOrFail($id) : new InventoryItem(['account_id' => $a->id]);
        $i->fill($d);
        $i->save();

        return response()->json(['data' => $i->load('component')]);
    }

    public function deleteItem(Request $r, string $id)
    {
        $i = InventoryItem::findOrFail($id);
        $a = Account::findOrFail($i->account_id);
        abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $a), 403);
        $i->update(['is_active' => false, 'archived_at' => now()]);

        return response()->json(['data' => $i]);
    }

    public function saveLocation(Request $r, ?string $id = null)
    {
        $d = $r->validate(['account_id' => 'required|uuid', 'branch_id' => 'nullable|uuid', 'code' => 'required|string|max:64', 'name' => 'required|string|max:160', 'notes' => 'nullable|string', 'is_active' => 'boolean']);
        $a = Account::findOrFail($d['account_id']);
        abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $a), 403);
        $l = $id ? InventoryLocation::where('account_id', $a->id)->findOrFail($id) : new InventoryLocation(['account_id' => $a->id]);
        $l->fill($d);
        $l->save();

        return response()->json(['data' => $l]);
    }

    public function deleteLocation(Request $r, string $id)
    {
        $l = InventoryLocation::findOrFail($id);
        $a = Account::findOrFail($l->account_id);
        abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $a), 403);
        $l->update(['is_active' => false, 'archived_at' => now()]);

        return response()->json(['data' => $l]);
    }

    public function items(Request $r)
    {
        return response()->json(['data' => InventoryItem::whereIn('account_id', $r->user()->memberships()->where('status', 'active')->pluck('account_id'))->where('is_active', true)->paginate(min((int) $r->integer('per_page', 25), 50))]);
    }

    public function locations(Request $r)
    {
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');

        $locations = InventoryLocation::whereIn('account_id', $ids)->where('is_active', true)->orderBy('name')->get()->filter(fn ($loc) => $this->canAccessLocation($r, $loc))->values();

        return response()->json(['data' => $locations]);
    }

    public function createPurchase(Request $r)
    {
        $d = $r->validate(['account_id' => 'required|uuid', 'branch_id' => 'nullable|uuid', 'supplier_id' => 'nullable|uuid', 'external_reference' => 'nullable|string', 'purchase_number' => 'required|string', 'purchase_date' => 'required|date', 'currency_code' => 'nullable|string|size:3', 'notes' => 'nullable|string', 'client_request_id' => 'required|uuid', 'lines' => 'required|array|min:1', 'lines.*.inventory_item_id' => 'required|uuid', 'lines.*.quantity' => 'required|numeric|gt:0', 'lines.*.unit_cost' => 'nullable|numeric|min:0']);
        abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), Account::findOrFail($d['account_id'])), 403);

        return response()->json(['data' => app(PurchaseReceiptService::class)->purchase($d['account_id'], $d)], 201);
    }

    public function receive(Request $r, string $purchase)
    {
        $d = $r->validate(['location_id' => 'required|uuid', 'client_request_id' => 'required|uuid', 'lines' => 'required|array|min:1']);
        $loc = InventoryLocation::findOrFail($d['location_id']);
        abort_unless($this->canAccessLocation($r, $loc, true), 403);

        return response()->json(['data' => app(PurchaseReceiptService::class)->receive($purchase, $loc, $d['lines'], $d['client_request_id'])], 201);
    }

    public function opening(Request $r)
    {
        $d = $r->validate(['item_id' => 'required|uuid', 'location_id' => 'required|uuid', 'quantity' => 'required|numeric|gt:0', 'unit_cost' => 'nullable|numeric|min:0', 'reason' => 'required|string', 'occurred_at' => 'nullable|date', 'client_request_id' => 'required|uuid']);
        $item = InventoryItem::findOrFail($d['item_id']);
        $loc = InventoryLocation::findOrFail($d['location_id']);
        abort_unless($this->canAccessLocation($r, $loc, true) && $item->account_id === $loc->account_id, 403);

        return response()->json(['data' => app(InventoryLedgerService::class)->inbound($item, $loc, $d['quantity'], $d['unit_cost'] ?? null, 'opening_balance', $d['client_request_id'], $d['reason'], $d['occurred_at'] ?? null)], 201);
    }

    public function balance(Request $r, string $item, string $location)
    {
        $inventoryItem = InventoryItem::findOrFail($item);
        abort_unless($this->canAccessLocation($r, InventoryLocation::findOrFail($location)) && $inventoryItem->account_id === InventoryLocation::findOrFail($location)->account_id, 403);

        return response()->json(['data' => ['inventory_item_id' => $item, 'location_id' => $location, 'quantity' => app(InventoryLedgerService::class)->balance($item, $location)]]);
    }

    public function transfer(Request $r)
    {
        $d = $r->validate(['item_id' => 'required|uuid', 'from_location_id' => 'required|uuid', 'to_location_id' => 'required|uuid', 'quantity' => 'required|numeric|min:0.0001', 'client_request_id' => 'required|uuid']);
        $item = InventoryItem::findOrFail($d['item_id']);
        $loc = InventoryLocation::findOrFail($d['from_location_id']);
        $to = InventoryLocation::findOrFail($d['to_location_id']);
        abort_unless($this->canAccessLocation($r, $loc, true) && $this->canAccessLocation($r, $to, true) && $item->account_id === $loc->account_id && $item->account_id === $to->account_id, 403);

        return response()->json(['data' => app(InventoryLedgerService::class)->transfer($item, $loc, $to, $d['quantity'], $d['client_request_id'])], 201);
    }

    public function adjust(Request $r)
    {
        $d = $r->validate(['item_id' => 'required|uuid', 'location_id' => 'required|uuid', 'quantity' => 'required|numeric|not_in:0', 'reason' => 'required|string', 'unit_cost' => 'nullable|numeric|min:0', 'client_request_id' => 'required|uuid']);
        $item = InventoryItem::findOrFail($d['item_id']);
        $loc = InventoryLocation::findOrFail($d['location_id']);
        abort_unless($this->canAccessLocation($r, $loc, true) && $item->account_id === $loc->account_id, 403);
        $ledger = app(InventoryLedgerService::class);
        $m = $d['quantity'] > 0 ? $ledger->inbound($item, $loc, $d['quantity'], $d['unit_cost'] ?? null, 'adjustment_in', $d['client_request_id'], $d['reason']) : $ledger->outbound($item, $loc, abs($d['quantity']), 'adjustment_out', $d['client_request_id'], null, $d['reason']);

        return response()->json(['data' => $m], 201);
    }

    public function replace(Request $r, string $component)
    {
        $d = $r->validate(['inventory_source' => 'required|in:inventory,external_untracked', 'inventory_item_id' => 'required_if:inventory_source,inventory|nullable|uuid', 'inventory_location_id' => 'required_if:inventory_source,inventory|nullable|uuid', 'quantity' => 'required_if:inventory_source,inventory|nullable|numeric|min:0.0001', 'replaced_at' => 'nullable|date', 'external_reason' => 'required_if:inventory_source,external_untracked|nullable|string', 'notes' => 'nullable|string', 'client_request_id' => 'required|uuid']);
        $mc = MachineComponent::findOrFail($component);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $mc->machine, true), 403);
        if ($d['inventory_source'] === 'inventory' && $d['inventory_location_id']) {
            abort_unless($this->canAccessLocation($r, InventoryLocation::findOrFail($d['inventory_location_id']), true), 403);
        }

        return response()->json(['data' => app(ReplaceMachineComponent::class)->execute($mc, $d)], 201);
    }

    private function canAccessLocation(Request $r, InventoryLocation $loc, bool $write = false): bool
    {
        $account = Account::find($loc->account_id);
        if (! $account || ! app(AccountAccessResolver::class)->canAccess($r->user(), $account)) {
            return false;
        }
        if ($write && ! app(AccountAccessResolver::class)->canManageOperational($r->user(), $account)) {
            return false;
        }

        return ! $loc->branch_id || app(BranchAccessResolver::class)->canAccess($r->user(), Branch::find($loc->branch_id));
    }
}
