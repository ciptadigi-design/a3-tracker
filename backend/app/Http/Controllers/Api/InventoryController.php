<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OperationalPersonResource;
use App\Models\Account;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventorySupplier;
use App\Models\MachineComponent;
use App\Models\SupplierBranchAssignment;
use App\Services\AccountAccessResolver;
use App\Services\BranchAccessResolver;
use App\Services\InventoryLedgerService;
use App\Services\MachineAccessResolver;
use App\Services\MovementSummaryBuilder;
use App\Services\OperationalPersonEligibilityService;
use App\Services\PurchaseReceiptService;
use App\Services\PurchaseSummaryBuilder;
use App\Services\ReceiptSummaryBuilder;
use App\Services\ReplaceMachineComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function workspace(Request $r, string $account, string $branch)
    {
        $a = Account::findOrFail($account);
        abort_unless(app(AccountAccessResolver::class)->canAccess($r->user(), $a), 403);
        $b = Branch::where('id', $branch)->where('account_id', $account)->firstOrFail();
        abort_unless(app(BranchAccessResolver::class)->canAccess($r->user(), $b), 403);
        $items = InventoryItem::where('account_id', $account)->where('is_active', true)->with('component')->orderBy('name')->get();
        $locations = InventoryLocation::where('account_id', $account)->where('branch_id', $branch)->where('is_active', true)->orderBy('name')->get();
        $ledger = app(InventoryLedgerService::class);
        $balances = $locations->flatMap(fn ($l) => $items->map(fn ($i) => ['account_id' => $account, 'inventory_item_id' => $i->id, 'location_id' => $l->id, 'quantity' => $ledger->balance($i->id, $l->id)]))->values();
        $purchases = DB::table('purchases')->where('account_id', $account)->where('branch_id', $branch)->orderByDesc('purchase_date')->get();
        $purchaseIds = $purchases->pluck('id');
        $locationIds = $locations->pluck('id');
        $people = app(OperationalPersonEligibilityService::class)->forBranch($account, $branch);
        $suppliers = InventorySupplier::where('account_id', $account)->where('is_active', true)
            ->visibleToBranch($branch)
            ->orderBy('name')->get();
        $purchaseLines = $purchaseIds->isEmpty() ? collect() : DB::table('purchase_lines')->where('account_id', $account)->whereIn('purchase_id', $purchaseIds)->get();
        $purchaseLineIds = $purchaseLines->pluck('id');
        $receiptLines = $purchaseLineIds->isEmpty() ? collect() : DB::table('receipt_lines')->where('account_id', $account)->whereIn('purchase_line_id', $purchaseLineIds)->get();
        // M2.17.4.1: historical purchase evidence must resolve the supplier name/code
        // snapshot regardless of current branch eligibility - a supplier that is later
        // reassigned away from this branch must not turn its past purchases into
        // "Unknown supplier". Deliberately unfiltered by branch (unlike $suppliers,
        // above, which is the current NEW-purchase eligibility set).
        $supplierLookup = InventorySupplier::where('account_id', $account)->get(['id', 'name', 'code']);
        $purchaseSummary = app(PurchaseSummaryBuilder::class)->build($purchases, $purchaseLines, $receiptLines, $items, $supplierLookup);
        // M2.17.5: Movements and Receipts need the same "resolve names independent of
        // current branch/active state" treatment $supplierLookup already gets above -
        // an archived location or a since-deactivated user must not turn historical
        // evidence blank. Deliberately unfiltered by branch/is_active for this reason.
        $locationLookup = InventoryLocation::where('account_id', $account)->get(['id', 'name']);
        $userLookup = DB::table('users')->get(['id', 'name']);
        $rawMovements = DB::table('inventory_movements')->where('account_id', $account)->whereIn('location_id', $locationIds)->orderByDesc('occurred_at')->limit(500)->get();
        $movements = app(MovementSummaryBuilder::class)->build($rawMovements, $items, $locationLookup, $userLookup);
        $rawReceipts = $purchaseIds->isEmpty() || $locationIds->isEmpty() ? collect() : DB::table('receipts')->where('account_id', $account)->whereIn('purchase_id', $purchaseIds)->whereIn('location_id', $locationIds)->get();
        $receiptLinesForReceipts = $receiptLines->whereIn('receipt_id', $rawReceipts->pluck('id'));
        $receiptMovements = $rawReceipts->isEmpty() ? collect() : DB::table('inventory_movements')->where('account_id', $account)->where('movement_type', 'receipt')->get();
        $receipts = app(ReceiptSummaryBuilder::class)->build($rawReceipts, $receiptLinesForReceipts, $purchases, $items, $supplierLookup, $locationLookup, $receiptMovements);
        $costPositions = $locationIds->isEmpty() ? collect() : DB::table('fifo_layers')
            ->where('account_id', $account)
            ->whereIn('location_id', $locationIds)
            ->where('remaining_quantity', '>', 0)
            ->get()
            ->groupBy(fn ($row) => $row->inventory_item_id.'::'.$row->location_id)
            ->map(function ($rows) {
                $known = $rows->filter(fn ($row) => $row->unit_cost !== null);

                return [
                    'inventory_item_id' => $rows->first()->inventory_item_id,
                    'location_id' => $rows->first()->location_id,
                    'total_quantity' => (float) $rows->sum('remaining_quantity'),
                    'known_cost_quantity' => (float) $known->sum('remaining_quantity'),
                    'unknown_cost_quantity' => (float) $rows->sum('remaining_quantity') - (float) $known->sum('remaining_quantity'),
                    'known_inventory_cost' => round((float) $known->sum(fn ($row) => $row->remaining_quantity * $row->unit_cost), 2),
                    'cost_layer_count' => $rows->count(),
                ];
            })->values();

        return response()->json(['data' => ['branchId' => $branch, 'items' => $items, 'locations' => $locations, 'suppliers' => $suppliers, 'balances' => $balances, 'totals' => $items->map(fn ($i) => ['account_id' => $account, 'inventory_item_id' => $i->id, 'quantity' => $balances->where('inventory_item_id', $i->id)->sum('quantity')])->values(), 'movements' => $movements, 'components' => DB::table('component_catalogs')->where('is_active', true)->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $account))->orderBy('name')->get(), 'people' => OperationalPersonResource::collection($people), 'purchases' => $purchaseSummary['purchases'], 'purchaseLines' => $purchaseSummary['lines'], 'receipts' => $receipts, 'lastPrices' => [], 'costHistory' => [], 'costPositions' => $costPositions]]);
    }

    // M2.17.4.1: the Supplier Master list previously ignored branch context entirely and
    // showed every account supplier regardless of the active branch, while the Purchase
    // picker (workspace(), above) already enforced branch eligibility - a Production
    // acceptance test found a supplier restricted to Tuparev still listed while viewing
    // Graha. This now requires the same account+branch context as workspace() and
    // applies the identical visibleToBranch() rule, so the two views cannot drift apart.
    // include_ineligible=1 bypasses the branch filter for the "attach an existing
    // supplier to this branch" admin workflow - it must never be reachable without
    // canManageOperational, since it exposes suppliers outside the caller's branch scope.
    public function suppliers(Request $r)
    {
        $d = $r->validate(['account_id' => 'required|uuid', 'branch_id' => 'required|uuid', 'include_ineligible' => 'sometimes|boolean']);
        $a = Account::findOrFail($d['account_id']);
        abort_unless(app(AccountAccessResolver::class)->canAccess($r->user(), $a), 403);
        $b = Branch::where('id', $d['branch_id'])->where('account_id', $a->id)->firstOrFail();
        abort_unless(app(BranchAccessResolver::class)->canAccess($r->user(), $b), 403);

        $includeIneligible = (bool) ($d['include_ineligible'] ?? false);
        if ($includeIneligible) {
            abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $a), 403);
        }

        $query = InventorySupplier::where('account_id', $a->id)->with('branchAssignments');
        if (! $includeIneligible) {
            $query->visibleToBranch($b->id);
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function assignSupplierBranch(Request $r, string $id)
    {
        $s = InventorySupplier::findOrFail($id);
        $a = Account::findOrFail($s->account_id);
        abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $a), 403);
        $d = $r->validate(['branch_id' => 'required|uuid']);
        $b = Branch::where('id', $d['branch_id'])->where('account_id', $s->account_id)->firstOrFail();
        $assignment = SupplierBranchAssignment::firstOrCreate(['supplier_id' => $s->id, 'branch_id' => $b->id], ['account_id' => $s->account_id]);

        return response()->json(['data' => $assignment], 201);
    }

    public function unassignSupplierBranch(Request $r, string $id, string $branchId)
    {
        $s = InventorySupplier::findOrFail($id);
        $a = Account::findOrFail($s->account_id);
        abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $a), 403);
        SupplierBranchAssignment::where('supplier_id', $s->id)->where('branch_id', $branchId)->delete();

        return response()->noContent();
    }

    // Historical Supabase/AI-assisted migration rows used "-" as a placeholder for
    // "no email on file" in a field that is otherwise a real, optional email address.
    // Laravel's `email` rule rejects that placeholder outright, so Edit fails on
    // legacy suppliers even though the user changed nothing about the email. Only
    // this field gets placeholder normalization - Notes/Address legitimately contain
    // free text like "-" and must not be silently nulled.
    private const EMAIL_PLACEHOLDER_VALUES = ['-', '--', 'n/a', 'na', 'none', 'null'];

    private function normalizeSupplierEmailPlaceholder(Request $r): void
    {
        $email = $r->input('email');
        if (is_string($email) && in_array(strtolower(trim($email)), self::EMAIL_PLACEHOLDER_VALUES, true)) {
            $r->merge(['email' => null]);
        }
    }

    public function saveSupplier(Request $r, ?string $id = null)
    {
        $this->normalizeSupplierEmailPlaceholder($r);
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
        // M2.17.5: unlike opening()/adjust()/transfer(), this endpoint never accepted or
        // resolved person_id at all - the frontend already sent it, but it was silently
        // dropped by validate()'s whitelist, so every Goods Receipt movement persisted
        // with a null PIC regardless of what the operator selected.
        $d = $r->validate(['location_id' => 'required|uuid', 'person_id' => 'nullable|uuid', 'client_request_id' => 'required|uuid', 'lines' => 'required|array|min:1']);
        $loc = InventoryLocation::findOrFail($d['location_id']);
        abort_unless($this->canAccessLocation($r, $loc, true), 403);
        [$personId, $personName] = $this->resolveOperator($loc, $d['person_id'] ?? null);

        return response()->json(['data' => app(PurchaseReceiptService::class)->receive($purchase, $loc, $d['lines'], $d['client_request_id'], $personId, $personName, $r->user()->id)], 201);
    }

    public function opening(Request $r)
    {
        $d = $r->validate(['item_id' => 'required|uuid', 'location_id' => 'required|uuid', 'quantity' => 'required|numeric|gt:0', 'unit_cost' => 'nullable|numeric|min:0', 'reason' => 'required|string', 'occurred_at' => 'nullable|date', 'person_id' => 'nullable|uuid', 'client_request_id' => 'required|uuid']);
        $item = InventoryItem::findOrFail($d['item_id']);
        $loc = InventoryLocation::findOrFail($d['location_id']);
        abort_unless($this->canAccessLocation($r, $loc, true) && $item->account_id === $loc->account_id, 403);
        [$personId, $personName] = $this->resolveOperator($loc, $d['person_id'] ?? null);

        return response()->json(['data' => app(InventoryLedgerService::class)->inbound($item, $loc, $d['quantity'], $d['unit_cost'] ?? null, 'opening_balance', $d['client_request_id'], $d['reason'], $d['occurred_at'] ?? null, $personId, $personName, $r->user()->id)], 201);
    }

    public function balance(Request $r, string $item, string $location)
    {
        $inventoryItem = InventoryItem::findOrFail($item);
        abort_unless($this->canAccessLocation($r, InventoryLocation::findOrFail($location)) && $inventoryItem->account_id === InventoryLocation::findOrFail($location)->account_id, 403);

        return response()->json(['data' => ['inventory_item_id' => $item, 'location_id' => $location, 'quantity' => app(InventoryLedgerService::class)->balance($item, $location)]]);
    }

    public function transfer(Request $r)
    {
        $d = $r->validate(['item_id' => 'required|uuid', 'from_location_id' => 'required|uuid', 'to_location_id' => 'required|uuid', 'quantity' => 'required|numeric|min:0.0001', 'person_id' => 'nullable|uuid', 'client_request_id' => 'required|uuid']);
        $item = InventoryItem::findOrFail($d['item_id']);
        $loc = InventoryLocation::findOrFail($d['from_location_id']);
        $to = InventoryLocation::findOrFail($d['to_location_id']);
        abort_unless($this->canAccessLocation($r, $loc, true) && $this->canAccessLocation($r, $to, true) && $item->account_id === $loc->account_id && $item->account_id === $to->account_id, 403);
        [$personId, $personName] = $this->resolveOperator($loc, $d['person_id'] ?? null);

        return response()->json(['data' => app(InventoryLedgerService::class)->transfer($item, $loc, $to, $d['quantity'], $d['client_request_id'], $personId, $personName, $r->user()->id)], 201);
    }

    public function adjust(Request $r)
    {
        $d = $r->validate(['item_id' => 'required|uuid', 'location_id' => 'required|uuid', 'quantity' => 'required|numeric|not_in:0', 'reason' => 'required|string', 'unit_cost' => 'nullable|numeric|min:0', 'person_id' => 'nullable|uuid', 'client_request_id' => 'required|uuid']);
        $item = InventoryItem::findOrFail($d['item_id']);
        $loc = InventoryLocation::findOrFail($d['location_id']);
        abort_unless($this->canAccessLocation($r, $loc, true) && $item->account_id === $loc->account_id, 403);
        [$personId, $personName] = $this->resolveOperator($loc, $d['person_id'] ?? null);
        $ledger = app(InventoryLedgerService::class);
        $m = $d['quantity'] > 0 ? $ledger->inbound($item, $loc, $d['quantity'], $d['unit_cost'] ?? null, 'adjustment_in', $d['client_request_id'], $d['reason'], null, $personId, $personName, $r->user()->id) : $ledger->outbound($item, $loc, abs($d['quantity']), 'adjustment_out', $d['client_request_id'], null, $d['reason'], null, $personId, $personName, $r->user()->id);

        return response()->json(['data' => $m], 201);
    }

    public function replace(Request $r, string $component)
    {
        $d = $r->validate(['inventory_source' => 'required|in:inventory,external_untracked', 'inventory_item_id' => 'required_if:inventory_source,inventory|nullable|uuid', 'inventory_location_id' => 'required_if:inventory_source,inventory|nullable|uuid', 'quantity' => 'required_if:inventory_source,inventory|nullable|numeric|min:0.0001', 'replaced_at' => 'nullable|date', 'external_reason' => 'required_if:inventory_source,external_untracked|nullable|string', 'notes' => 'nullable|string', 'performed_by_person_id' => 'nullable|uuid', 'performed_by_name' => 'nullable|string|max:160', 'client_request_id' => 'required|uuid']);
        $mc = MachineComponent::findOrFail($component);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $mc->machine, true), 403);
        if ($d['inventory_source'] === 'inventory' && $d['inventory_location_id']) {
            abort_unless($this->canAccessLocation($r, InventoryLocation::findOrFail($d['inventory_location_id']), true), 403);
        }
        if (! empty($d['performed_by_person_id'])) {
            $person = app(OperationalPersonEligibilityService::class)->eligible($mc->machine, $d['performed_by_person_id']);
            if (! $person) {
                throw ValidationException::withMessages(['performed_by_person_id' => 'Selected PIC is not an active canonical Operator for this machine.']);
            }
            $d['performed_by_person_id'] = $person->id;
            $d['performed_by_name'] = $person->name;
        }
        $d['entered_by'] = $r->user()->id;

        return response()->json(['data' => app(ReplaceMachineComponent::class)->execute($mc, $d)], 201);
    }

    private function resolveOperator(InventoryLocation $loc, ?string $personId): array
    {
        if (! $personId) {
            return [null, null];
        }
        $person = app(OperationalPersonEligibilityService::class)->eligibleOperatorForLocation($loc, $personId);
        if (! $person) {
            throw ValidationException::withMessages(['person_id' => 'Selected PIC is not an active canonical Operator for this location.']);
        }

        return [$person->id, $person->name];
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
