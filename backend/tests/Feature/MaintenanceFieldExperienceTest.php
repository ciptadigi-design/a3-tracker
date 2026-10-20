<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\DowntimeEvent;
use App\Models\Machine;
use App\Models\MachineErrorCode;
use App\Models\MachineModel;
use App\Models\MaintenanceAction;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceOfficialErrorEntry;
use App\Models\MaintenanceTicket;
use App\Models\Manufacturer;
use App\Models\User;
use App\Services\MaintenanceFieldExperienceSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3.3B - Pengalaman Lapangan search. Fixtures only; nothing here touches Production.
 */
class MaintenanceFieldExperienceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/maintenance/field-experiences';

    private function fixture(): array
    {
        $account = Account::create(['code' => 'HOME', 'name' => 'Home', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'OTHER', 'name' => 'Other', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $main = Branch::create(['account_id' => $account->id, 'code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $second = Branch::create(['account_id' => $account->id, 'code' => 'SECOND', 'name' => 'Second', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $foreignBranch = Branch::create(['account_id' => $foreign->id, 'code' => 'FRGN', 'name' => 'Foreign', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $maker = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $modelA = MachineModel::create(['manufacturer_id' => $maker->id, 'model_code' => 'C1070', 'name' => 'bizhub PRESS C1070']);
        $modelB = MachineModel::create(['manufacturer_id' => $maker->id, 'model_code' => 'C3070', 'name' => 'bizhub C3070']);
        $m = fn (Account $a, Branch $b, MachineModel $mod, string $code) => Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $mod->id, 'machine_code' => $code, 'display_name' => $code, 'status' => 'active'])->fresh(); // fresh(): the model assigns a UUID object; tests need the string id
        $f = [
            'account' => $account, 'foreign' => $foreign, 'main' => $main, 'second' => $second, 'foreignBranch' => $foreignBranch, 'modelA' => $modelA, 'modelB' => $modelB, 'maker' => $maker,
            'machineMain' => $m($account, $main, $modelA, 'A3-01'),
            'machineSecond' => $m($account, $second, $modelA, 'A3-02'),
            'machineOtherModel' => $m($account, $second, $modelB, 'B3-01'),
            'foreignMachine' => $m($foreign, $foreignBranch, $modelA, 'F-01'),
        ];
        $f['owner'] = $this->member($account, 'owner');
        $f['admin'] = $this->member($account, 'admin', $main);
        $f['operator'] = $this->member($account, 'operator', $main);
        $f['foreigner'] = $this->member($foreign, 'owner');
        $f['stranger'] = User::factory()->create(['status' => 'active']);

        return $f;
    }

    private function member(Account $account, string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);
        if ($branch) {
            AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        }

        return $user;
    }

    /** An eligible ticket by default; $o overrides any column. */
    private function ticket(array $f, array $o = [], string $machine = 'machineMain'): MaintenanceTicket
    {
        $mach = $f[$machine];

        return MaintenanceTicket::create($o + [
            'account_id' => $mach->account_id, 'branch_id' => $mach->branch_id, 'machine_id' => $mach->id,
            'title' => 'Hasil kotor', 'type' => 'breakdown', 'status' => 'DONE', 'reported_by' => $f['owner']->id,
            'resolution_cause' => 'Drum kotor', 'resolution_action' => 'Drum dibersihkan', 'resolution_test_result' => 'normal',
            'opened_at' => '2026-10-10 08:00:00', 'resolved_at' => '2026-10-10 09:00:00',
        ]);
    }

    private function withDowntime(array $f, MaintenanceTicket $t, ?string $code, string $start = '2026-10-10 08:00:00', ?string $end = '2026-10-10 10:30:00'): DowntimeEvent
    {
        $e = DowntimeEvent::create(['account_id' => $t->account_id, 'branch_id' => $t->branch_id, 'machine_id' => $t->machine_id, 'cause' => 'error_code', 'error_code_text' => $code, 'actual_start_at' => $start, 'actual_end_at' => $end, 'active_key' => null]);
        $t->update(['downtime_event_id' => $e->id]);

        return $e;
    }

    private function action(MaintenanceTicket $t, string $text, array $o = []): MaintenanceAction
    {
        return MaintenanceAction::create($o + ['account_id' => $t->account_id, 'ticket_id' => $t->id, 'action_description' => $text]);
    }

    private function search(array $f, User $as, string $q, array $extra = [], ?string $account = null)
    {
        return $this->actingAs($as)->getJson(self::URL.'?'.http_build_query(['account_id' => $account ?? $f['account']->id, 'q' => $q] + $extra));
    }

    private function ids($response): array
    {
        return array_column($response->assertOk()->json('data'), 'id');
    }

    // ---------------------------------------------------------------- codes

    public function test_c_code_matches_every_common_format_from_the_linked_downtime(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $this->withDowntime($f, $t, 'c 0213');
        $other = $this->ticket($f);
        $this->withDowntime($f, $other, 'C-0999');

        foreach (['C-0213', 'C0213', 'c0213', 'c-0213', ' C 0213 '] as $q) {
            $r = $this->search($f, $f['owner'], $q);
            $this->assertSame([$t->id], $this->ids($r), "query [$q]");
            $r->assertJsonPath('query.kind', 'code')->assertJsonPath('query.codes', ['C-0213'])
                ->assertJsonPath('data.0.error_code', 'C-0213')->assertJsonPath('data.0.error_code_source', 'downtime')->assertJsonPath('data.0.matched_by', 'code');
        }
    }

    public function test_j_code_is_independent_and_never_matches_the_c_family(): void
    {
        $f = $this->fixture();
        $c = $this->ticket($f);
        $this->withDowntime($f, $c, 'C-2113');
        $j = $this->ticket($f);
        $this->withDowntime($f, $j, 'J-2113');

        foreach (['J-2113', 'J2113', 'j2113'] as $q) {
            $this->assertSame([$j->id], $this->ids($this->search($f, $f['owner'], $q)), "query [$q]");
        }
        $this->assertSame([$c->id], $this->ids($this->search($f, $f['owner'], 'C-2113')));
        $this->search($f, $f['owner'], 'J-2113')->assertJsonPath('data.0.error_code', 'J-2113')->assertJsonPath('query.codes', ['J-2113']);
    }

    public function test_numeric_only_searches_both_families_without_merging_their_identities(): void
    {
        $f = $this->fixture();
        $c = $this->ticket($f);
        $this->withDowntime($f, $c, 'C-0213', '2026-10-10 08:00:00');
        $j = $this->ticket($f);
        $this->withDowntime($f, $j, 'J-0213', '2026-10-11 08:00:00');
        $only = $this->ticket($f);
        $this->withDowntime($f, $only, 'J-2113');

        $r = $this->search($f, $f['owner'], '0213')->assertOk()->assertJsonPath('query.kind', 'numeric')->assertJsonPath('query.codes', ['C-0213', 'J-0213'])->assertJsonPath('meta.total', 2);
        $byId = collect($r->json('data'))->pluck('error_code', 'id');
        $this->assertSame('C-0213', $byId[$c->id]);
        $this->assertSame('J-0213', $byId[$j->id]);

        $this->assertSame([$only->id], $this->ids($this->search($f, $f['owner'], '2113')));
        // Fewer than four digits is ordinary text, not a code family guess.
        $this->search($f, $f['owner'], '213')->assertJsonPath('query.kind', 'text')->assertJsonPath('meta.total', 0);
    }

    public function test_code_is_resolved_from_official_reference_account_reference_and_downtime_text(): void
    {
        $f = $this->fixture();
        $doc = MaintenanceDocument::create(['account_id' => null, 'manufacturer_id' => $f['maker']->id, 'title' => 'Manual', 'document_type' => 'SERVICE_MANUAL', 'file_reference' => 'm.pdf', 'status' => 'PUBLISHED', 'is_active' => true]);
        $official = MaintenanceOfficialErrorEntry::create(['document_id' => $doc->id, 'code' => 'C-3913', 'variant_key' => 'GENERAL', 'raw_source_text' => 'x']);
        $master = MachineErrorCode::create(['account_id' => $f['account']->id, 'code' => 'J-1101', 'title' => 'Jam']);

        $viaOfficial = $this->ticket($f, ['official_error_entry_id' => $official->id]);
        $viaMaster = $this->ticket($f, ['error_code_id' => $master->id]);
        $viaDowntime = $this->ticket($f);
        $this->withDowntime($f, $viaDowntime, 'C-4444');

        $this->assertSame([$viaOfficial->id], $this->ids($this->search($f, $f['owner'], 'c3913')));
        $this->search($f, $f['owner'], 'C3913')->assertJsonPath('data.0.error_code_source', 'official');
        $this->assertSame([$viaMaster->id], $this->ids($this->search($f, $f['owner'], 'J1101')));
        $this->search($f, $f['owner'], 'J1101')->assertJsonPath('data.0.error_code_source', 'account');
        $this->assertSame([$viaDowntime->id], $this->ids($this->search($f, $f['owner'], 'C4444')));
    }

    public function test_ticket_without_any_error_code_is_found_by_the_code_written_in_its_text(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f, ['description' => 'Layar menampilkan C0213 setelah warm up']);
        $this->ticket($f, ['description' => 'Tidak ada kode']);

        $r = $this->search($f, $f['owner'], 'C-0213');
        $this->assertSame([$t->id], $this->ids($r));
        $r->assertJsonPath('data.0.matched_by', 'code_in_text')->assertJsonPath('data.0.error_code', null);
    }

    // ---------------------------------------------------------- description

    public function test_descriptive_search_matches_every_term_across_ticket_and_resolution_text(): void
    {
        $f = $this->fixture();
        $a = $this->ticket($f, ['title' => 'Hasil Kotor']);
        $b = $this->ticket($f, ['title' => 'Lain', 'description' => 'Kertas macet di paper exit']);
        $c = $this->ticket($f, ['title' => 'Lain', 'resolution_action' => 'Mengganti motor yang tidak berfungsi']);
        $d = $this->ticket($f, ['title' => 'Lain', 'resolution_notes' => 'Pantau area Warna Tidak Rata']);
        $e = $this->ticket($f, ['title' => 'Lain', 'resolution_cause' => 'Sensor paper kotor']);

        $this->assertSame([$a->id], $this->ids($this->search($f, $f['owner'], 'hasil kotor')));
        $this->assertSame([$a->id], $this->ids($this->search($f, $f['owner'], 'KOTOR hasil')), 'order and case do not matter');
        $this->assertSame([$b->id], $this->ids($this->search($f, $f['owner'], 'paper exit')));
        $this->assertSame([$c->id], $this->ids($this->search($f, $f['owner'], 'motor tidak berfungsi')));
        $this->assertSame([$d->id], $this->ids($this->search($f, $f['owner'], 'warna tidak rata')));
        $this->assertEqualsCanonicalizing([$e->id, $b->id], $this->ids($this->search($f, $f['owner'], 'paper')));
        $this->search($f, $f['owner'], 'paper hasil')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_like_wildcards_are_escaped(): void
    {
        $f = $this->fixture();
        $pct = $this->ticket($f, ['description' => 'toner habis 100% terpakai']);
        $this->ticket($f, ['description' => 'toner habis 1000 lembar']);
        $und = $this->ticket($f, ['description' => 'kode drum_atas rusak']);
        $this->ticket($f, ['description' => 'kode drumXatas rusak']);
        $bang = $this->ticket($f, ['description' => 'alarm!! menyala']);

        $this->assertSame([$pct->id], $this->ids($this->search($f, $f['owner'], '100%')));
        $this->assertSame([$und->id], $this->ids($this->search($f, $f['owner'], 'drum_atas')));
        $this->assertSame([$bang->id], $this->ids($this->search($f, $f['owner'], 'alarm!!')));
        $this->search($f, $f['owner'], '%%')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_empty_results_are_a_normal_response(): void
    {
        $f = $this->fixture();
        $this->ticket($f);
        $this->search($f, $f['owner'], 'tidak ada yang cocok')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('meta.last_page', 1);
        $this->search($f, $f['owner'], 'J-9999')->assertOk()->assertJsonPath('data', []);
    }

    // ------------------------------------------------------------ isolation

    public function test_search_spans_all_branches_of_the_account_and_never_another_account(): void
    {
        $f = $this->fixture();
        $home = $this->ticket($f, ['title' => 'Warna tidak rata'], 'machineMain');
        $sibling = $this->ticket($f, ['title' => 'Warna tidak rata'], 'machineSecond');
        $foreignTicket = $this->ticket($f, ['title' => 'Warna tidak rata', 'reported_by' => $f['foreigner']->id], 'foreignMachine');
        $this->withDowntime($f, $foreignTicket, 'C-0213');
        $this->withDowntime($f, $home, 'C-0213');

        $got = $this->ids($this->search($f, $f['owner'], 'warna tidak rata'));
        $this->assertEqualsCanonicalizing([$home->id, $sibling->id], $got);
        $this->assertNotContains($foreignTicket->id, $got);
        $this->assertSame([$home->id], $this->ids($this->search($f, $f['owner'], 'C0213')), 'code search is account scoped too');

        // The branch-limited admin (MAIN only) still sees SECOND's experience.
        $this->assertEqualsCanonicalizing([$home->id, $sibling->id], $this->ids($this->search($f, $f['admin'], 'warna tidak rata')));
        // The foreign owner sees only their own account.
        $this->assertSame([$foreignTicket->id], $this->ids($this->search($f, $f['foreigner'], 'warna tidak rata', [], $f['foreign']->id)));
    }

    public function test_account_access_requires_active_membership_in_the_selected_account(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);

        $this->search($f, $f['foreigner'], 'hasil kotor')->assertForbidden();
        $this->search($f, $f['stranger'], 'hasil kotor')->assertForbidden();
        $this->search($f, $f['owner'], 'hasil kotor', [], (string) Str::uuid())->assertForbidden();
        $this->actingAs($f['foreigner'])->getJson(self::URL."/{$t->id}?account_id=".$f['account']->id)->assertForbidden();
        $this->actingAs($f['stranger'])->getJson(self::URL."/{$t->id}?account_id=".$f['account']->id)->assertForbidden();

        AccountMembership::where('user_id', $f['operator']->id)->update(['status' => 'suspended']);
        $this->search($f, $f['operator'], 'hasil kotor')->assertForbidden();
        $this->actingAs($f['operator'])->getJson(self::URL."/{$t->id}?account_id=".$f['account']->id)->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $this->getJson(self::URL.'?account_id='.$f['account']->id.'&q=hasil')->assertUnauthorized();
        $this->getJson(self::URL."/{$t->id}?account_id=".$f['account']->id)->assertUnauthorized();
    }

    public function test_summary_detail_is_account_scoped_with_a_uniform_not_found(): void
    {
        $f = $this->fixture();
        $home = $this->ticket($f);
        $foreignTicket = $this->ticket($f, [], 'foreignMachine');
        $open = $this->ticket($f, ['status' => 'OPEN', 'resolution_cause' => null, 'resolution_action' => null, 'resolution_test_result' => null]);

        $this->actingAs($f['owner'])->getJson(self::URL."/{$home->id}?account_id=".$f['account']->id)->assertOk()->assertJsonPath('data.id', $home->id);
        // Another account's ticket id, an ineligible ticket and an unknown id all look the same.
        foreach ([$foreignTicket->id, $open->id, (string) Str::uuid()] as $id) {
            $this->actingAs($f['owner'])->getJson(self::URL."/$id?account_id=".$f['account']->id)->assertNotFound();
        }
        $this->actingAs($f['foreigner'])->getJson(self::URL."/{$home->id}?account_id=".$f['foreign']->id)->assertNotFound();
        $this->actingAs($f['owner'])->getJson(self::URL.'/not-a-uuid?account_id='.$f['account']->id)->assertNotFound();
        $this->actingAs($f['owner'])->getJson(self::URL."/{$home->id}")->assertUnprocessable();
    }

    public function test_can_open_mirrors_ticket_detail_access_and_hides_the_internal_pic(): void
    {
        $f = $this->fixture();
        $mine = $this->ticket($f, ['internal_pic_name' => 'Andi', 'external_technician_name' => 'Budi', 'external_vendor_name' => 'Konica Minolta'], 'machineMain');
        $theirs = $this->ticket($f, ['internal_pic_name' => 'Siti', 'external_technician_name' => 'Joko'], 'machineSecond');
        $this->action($theirs, 'Cek unit', ['operator_name' => 'Siti', 'external_technician_name' => 'Joko']);

        $rows = collect($this->search($f, $f['admin'], 'hasil kotor')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows[$mine->id]['can_open']);
        $this->assertSame('Andi', $rows[$mine->id]['internal_pic_name']);
        $this->assertFalse($rows[$theirs->id]['can_open']);
        $this->assertNull($rows[$theirs->id]['internal_pic_name']);
        $this->assertNull($rows[$theirs->id]['external_technician_name'], 'list withholds technician names when the ticket cannot be opened');
        $this->assertSame('Budi', $rows[$mine->id]['external_technician_name']);
        $this->assertSame('Konica Minolta', $rows[$mine->id]['external_vendor_name']);

        // The existing ticket-detail rule is unchanged and agrees with can_open.
        $this->actingAs($f['admin'])->getJson("/api/v1/maintenance/tickets/{$mine->id}")->assertOk();
        $this->actingAs($f['admin'])->getJson("/api/v1/maintenance/tickets/{$theirs->id}")->assertForbidden();
        $this->actingAs($f['admin'])->patchJson("/api/v1/maintenance/tickets/{$theirs->id}/assign", ['assigned_to' => $f['admin']->id])->assertForbidden();

        // Owners can open everything; the summary of a closed-off ticket hides the PIC and operator names.
        $this->assertTrue(collect($this->search($f, $f['owner'], 'hasil kotor')->json('data'))->every(fn ($row) => $row['can_open']));
        $detail = $this->actingAs($f['admin'])->getJson(self::URL."/{$theirs->id}?account_id=".$f['account']->id)->assertOk()->json('data');
        $this->assertFalse($detail['can_open']);
        $this->assertNull($detail['internal_pic_name']);
        $this->assertNull($detail['activities'][0]['operator_name']);
        $this->assertNull($detail['activities'][0]['external_technician_name'], 'detail withholds technician names from people who cannot open the ticket');
        $this->assertNull($detail['external_technician_name']);
        $this->assertNull($detail['external_vendor_name']);
        $ownerDetail = $this->actingAs($f['owner'])->getJson(self::URL."/{$theirs->id}?account_id=".$f['account']->id)->json('data');
        $this->assertSame('Siti', $ownerDetail['internal_pic_name']);
        $this->assertSame('Siti', $ownerDetail['activities'][0]['operator_name']);
    }

    public function test_responses_never_expose_user_identity_or_audit_data(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f, ['internal_pic_name' => 'Andi', 'resolved_by' => $f['owner']->id, 'assigned_to' => $f['admin']->id]);
        $this->action($t, 'Cek', ['performed_by' => $f['admin']->id, 'operator_name' => 'Andi']);
        $this->withDowntime($f, $t, 'C-0213');

        $list = $this->search($f, $f['owner'], 'C-0213')->assertOk()->getContent();
        $detail = $this->actingAs($f['owner'])->getJson(self::URL."/{$t->id}?account_id=".$f['account']->id)->assertOk()->getContent();
        foreach ([$list, $detail] as $body) {
            foreach (['email', 'password', 'reported_by', 'resolved_by', 'assigned_to', 'performed_by', 'account_id', 'client_request_id', 'metadata', $f['owner']->email, $f['admin']->email, $f['owner']->id, $f['admin']->id, $f['account']->id] as $needle) {
                $this->assertStringNotContainsString((string) $needle, $body, "leaked [$needle]");
            }
        }
    }

    public function test_detail_links_an_official_entry_only_when_the_ticket_references_it_and_withholds_names_cross_branch(): void
    {
        $f = $this->fixture();
        $doc = MaintenanceDocument::create(['account_id' => null, 'manufacturer_id' => $f['maker']->id, 'title' => 'Manual', 'document_type' => 'SERVICE_MANUAL', 'file_reference' => 'm.pdf', 'status' => 'PUBLISHED', 'is_active' => true]);
        $official = MaintenanceOfficialErrorEntry::create(['document_id' => $doc->id, 'code' => 'C-3913', 'variant_key' => 'GENERAL', 'raw_source_text' => 'x']);
        $linked = $this->ticket($f, ['official_error_entry_id' => $official->id, 'external_technician_name' => 'Joko', 'external_vendor_name' => 'Vendor X'], 'machineSecond');
        $textOnly = $this->ticket($f, ['title' => 'C-3913 muncul'], 'machineSecond');
        $this->withDowntime($f, $textOnly, 'C-3913');

        $url = fn ($t) => self::URL."/{$t->id}?account_id=".$f['account']->id;
        $this->actingAs($f['admin'])->getJson($url($linked))->assertOk()
            ->assertJsonPath('data.can_open', false)
            ->assertJsonPath('data.official_reference.entry_id', $official->id)
            ->assertJsonPath('data.official_reference.code', 'C-3913')
            ->assertJsonPath('data.external_technician_name', null)
            ->assertJsonPath('data.external_vendor_name', null);
        // Matching text or downtime code never implies an official reference.
        $this->actingAs($f['admin'])->getJson($url($textOnly))->assertOk()->assertJsonPath('data.official_reference', null);
        // People who may open the ticket still see the names.
        $this->actingAs($f['owner'])->getJson($url($linked))->assertOk()->assertJsonPath('data.external_technician_name', 'Joko');

        // A reference to an entry whose document belongs to another account is not exposed.
        $private = MaintenanceDocument::create(['account_id' => $f['foreign']->id, 'manufacturer_id' => $f['maker']->id, 'title' => 'Private', 'document_type' => 'SERVICE_MANUAL', 'file_reference' => 'p.pdf', 'status' => 'PUBLISHED', 'is_active' => true]);
        $hidden = MaintenanceOfficialErrorEntry::create(['document_id' => $private->id, 'code' => 'C-9999', 'variant_key' => 'GENERAL', 'raw_source_text' => 'x']);
        $other = $this->ticket($f, ['official_error_entry_id' => $hidden->id], 'machineSecond');
        $this->actingAs($f['owner'])->getJson($url($other))->assertOk()->assertJsonPath('data.official_reference', null);
    }

    public function test_restricted_viewers_get_no_personal_names_anywhere_in_list_or_detail_json(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f, ['internal_pic_name' => 'PicRahasia', 'external_technician_name' => 'TeknisiRahasia', 'external_vendor_name' => 'VendorRahasia', 'reported_by' => $f['owner']->id], 'machineSecond');
        $this->action($t, 'Cek unit', ['operator_name' => 'OperatorRahasia', 'external_technician_name' => 'TeknisiAksi', 'vendor_name' => 'VendorAksi', 'performed_by' => $f['owner']->id]);
        $names = ['PicRahasia', 'TeknisiRahasia', 'VendorRahasia', 'OperatorRahasia', 'TeknisiAksi', 'VendorAksi', $f['owner']->email, $f['owner']->id, $f['account']->id];

        $list = $this->search($f, $f['admin'], 'hasil kotor')->assertOk()->assertJsonPath('data.0.can_open', false)->getContent();
        $detail = $this->actingAs($f['admin'])->getJson(self::URL."/{$t->id}?account_id=".$f['account']->id)->assertOk()->assertJsonPath('data.can_open', false)->getContent();
        foreach ([$list, $detail] as $body) {
            foreach ($names as $needle) {
                $this->assertStringNotContainsString((string) $needle, $body, "leaked [$needle] to a restricted viewer");
            }
        }
        // Authorized viewers keep the existing behaviour on both endpoints.
        $ownerList = $this->search($f, $f['owner'], 'hasil kotor')->json('data.0');
        $this->assertSame('PicRahasia', $ownerList['internal_pic_name']);
        $this->assertSame('TeknisiRahasia', $ownerList['external_technician_name']);
        $this->assertSame('VendorRahasia', $ownerList['external_vendor_name']);
    }

    public function test_both_endpoints_isolate_accounts_independently(): void
    {
        $f = $this->fixture();
        $home = $this->ticket($f, ['title' => 'Hasil kotor rumah']);
        $foreignTicket = $this->ticket($f, ['title' => 'Hasil kotor asing'], 'foreignMachine');

        // A member of another account cannot read this account's data, by list or by id.
        $this->search($f, $f['foreigner'], 'hasil kotor', [], $f['account']->id)->assertForbidden();
        $this->actingAs($f['foreigner'])->getJson(self::URL."/{$home->id}?account_id=".$f['account']->id)->assertForbidden();
        // Naming one's own account never reveals another account's ticket.
        $this->assertSame([$foreignTicket->id], $this->ids($this->search($f, $f['foreigner'], 'hasil kotor', [], $f['foreign']->id)));
        $this->actingAs($f['foreigner'])->getJson(self::URL."/{$home->id}?account_id=".$f['foreign']->id)->assertNotFound();
        $this->actingAs($f['owner'])->getJson(self::URL."/{$foreignTicket->id}?account_id=".$f['account']->id)->assertNotFound();
        $this->assertSame([$home->id], $this->ids($this->search($f, $f['owner'], 'hasil kotor')));
    }

    public function test_integration_code_and_description_searches_end_to_end(): void
    {
        $f = $this->fixture();
        $c = $this->ticket($f, ['title' => 'Layar blank'], 'machineSecond');
        $this->withDowntime($f, $c, 'C-0213');
        $j = $this->ticket($f, ['title' => 'Paper exit macet', 'description' => 'Kertas macet di paper exit'], 'machineSecond');
        $this->withDowntime($f, $j, 'J-2113');
        $motor = $this->ticket($f, ['title' => 'Motor', 'description' => 'motor tidak berfungsi saat start']);
        $both = [$this->ticket($f, ['title' => 'Kode 0213 J'], 'machineMain'), $this->ticket($f, ['title' => 'Kode 2113 C'], 'machineMain')];
        $this->withDowntime($f, $both[0], 'J-0213');
        $this->withDowntime($f, $both[1], 'C-2113');

        foreach (['C-0213', 'C0213', 'c0213'] as $q) {
            $this->assertSame([$c->id], $this->ids($this->search($f, $f['owner'], $q)), $q);
        }
        foreach (['J-2113', 'J2113'] as $q) {
            $this->assertSame([$j->id], $this->ids($this->search($f, $f['owner'], $q)), $q);
        }
        // Numeric only: both families, never merged into one identity.
        $r = $this->search($f, $f['owner'], '0213')->assertOk()->assertJsonPath('query.kind', 'numeric')->assertJsonPath('query.codes', ['C-0213', 'J-0213']);
        $this->assertEqualsCanonicalizing([$c->id, $both[0]->id], array_column($r->json('data'), 'id'));
        $codes = array_column($r->json('data'), 'error_code', 'id');
        $this->assertSame('C-0213', $codes[$c->id]);
        $this->assertSame('J-0213', $codes[$both[0]->id]);
        $r = $this->search($f, $f['owner'], '2113')->assertOk();
        $this->assertEqualsCanonicalizing([$j->id, $both[1]->id], array_column($r->json('data'), 'id'));
        // Descriptive text searches experiences and never invents a code.
        $r = $this->search($f, $f['owner'], 'paper exit')->assertOk()->assertJsonPath('query.kind', 'text')->assertJsonPath('query.codes', []);
        $this->assertSame([$j->id], array_column($r->json('data'), 'id'));
        $this->assertSame([$motor->id], $this->ids($this->search($f, $f['owner'], 'motor tidak berfungsi')));
    }

    public function test_the_projection_is_read_only(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $this->actingAs($f['owner'])->postJson(self::URL, ['account_id' => $f['account']->id])->assertStatus(405);
        $this->actingAs($f['owner'])->patchJson(self::URL."/{$t->id}", ['account_id' => $f['account']->id])->assertStatus(405);
        $this->actingAs($f['owner'])->deleteJson(self::URL."/{$t->id}?account_id=".$f['account']->id)->assertStatus(405);
        $this->assertSame(1, MaintenanceTicket::count());
    }

    // ---------------------------------------------------------- eligibility

    public function test_only_completed_breakdown_tickets_with_a_meaningful_resolution_are_eligible(): void
    {
        $f = $this->fixture();
        $ok = $this->ticket($f, ['title' => 'Ok lolos']);
        $monitoring = $this->ticket($f, ['title' => 'Ok lolos', 'resolution_test_result' => 'monitoring']);
        $boundary = $this->ticket($f, ['title' => 'Ok lolos', 'resolution_cause' => 'Lima!', 'resolution_action' => 'Lima!']);
        $multibyte = $this->ticket($f, ['title' => 'Ok lolos', 'resolution_cause' => 'Ünïcö', 'resolution_action' => 'Ünïcö']);

        $excluded = [
            'open' => ['status' => 'OPEN', 'resolution_cause' => null, 'resolution_action' => null, 'resolution_test_result' => null],
            'in progress' => ['status' => 'IN_PROGRESS', 'resolution_cause' => null, 'resolution_action' => null, 'resolution_test_result' => null],
            'cancelled' => ['status' => 'CANCELLED', 'resolution_cause' => null, 'resolution_action' => null, 'resolution_test_result' => null],
            'cancelled even with text' => ['status' => 'CANCELLED'],
            'preventive' => ['type' => 'preventive'],
            'inspection' => ['type' => 'inspection'],
            'cause 4 chars' => ['resolution_cause' => 'Abcd'],
            'action 4 chars' => ['resolution_action' => 'Abcd'],
            'cause blank' => ['resolution_cause' => '     '],
            'cause padded below 5' => ['resolution_cause' => '  Abc  '],
            'cause missing' => ['resolution_cause' => null],
            'action missing' => ['resolution_action' => null],
            'not normal' => ['resolution_test_result' => 'not_normal'],
            'no test result' => ['resolution_test_result' => null],
            'legacy done without resolution' => ['resolution_cause' => null, 'resolution_action' => null, 'resolution_test_result' => null],
        ];
        $bad = [];
        foreach ($excluded as $label => $override) {
            $bad[$label] = $this->ticket($f, ['title' => 'Ok lolos'] + $override)->id;
        }

        $r = $this->search($f, $f['owner'], 'ok lolos', ['per_page' => 25]);
        $got = $this->ids($r);
        $this->assertEqualsCanonicalizing([$ok->id, $monitoring->id, $boundary->id, $multibyte->id], $got);
        foreach ($bad as $label => $id) {
            $this->assertNotContains($id, $got, "[$label] must be excluded");
            $this->actingAs($f['owner'])->getJson(self::URL."/$id?account_id=".$f['account']->id)->assertNotFound();
        }
        $rows = collect($r->json('data'))->pluck('test_result', 'id');
        $this->assertSame('monitoring', $rows[$monitoring->id]);
        $this->assertSame('normal', $rows[$ok->id]);
        $this->assertSame(4, $r->json('meta.total'));
    }

    // ---------------------------------------------------------- corrections

    public function test_corrections_are_not_separate_experiences_and_the_latest_content_is_effective(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f, ['title' => 'Lain']);
        $orig = $this->action($t, 'Mengganti drum lama', ['performed_at' => '2026-10-10 08:10:00', 'operator_name' => 'Andi']);
        $this->action($t, 'Membersihkan blade lama', ['performed_at' => '2026-10-10 08:20:00', 'corrects_action_id' => $orig->id, 'correction_reason' => 'Salah tulis']);
        $this->action($t, 'Membersihkan blade cleaning', ['performed_at' => '2026-10-10 08:30:00', 'corrects_action_id' => $orig->id, 'correction_reason' => 'Lebih tepat']);
        $this->action($t, 'Test print ulang normal', ['performed_at' => '2026-10-10 08:40:00']);

        $this->assertSame([$t->id], $this->ids($this->search($f, $f['owner'], 'blade cleaning')));
        $this->assertSame([], $this->ids($this->search($f, $f['owner'], 'drum lama')), 'superseded original is not searchable');
        $this->assertSame([], $this->ids($this->search($f, $f['owner'], 'blade lama')), 'superseded correction is not searchable');
        // Matching the original and the correction at once still yields one ticket.
        $this->assertSame(1, $this->search($f, $f['owner'], 'blade')->json('meta.total'));

        $detail = $this->actingAs($f['owner'])->getJson(self::URL."/{$t->id}?account_id=".$f['account']->id)->assertOk()->json('data.activities');
        $this->assertCount(2, $detail);
        $this->assertSame('Membersihkan blade cleaning', $detail[0]['description']);
        $this->assertTrue($detail[0]['corrected']);
        $this->assertSame('Test print ulang normal', $detail[1]['description']);
        $this->assertFalse($detail[1]['corrected']);
        // The audit trail itself is untouched.
        $this->assertSame(4, MaintenanceAction::where('ticket_id', $t->id)->count());
        $this->assertSame('Mengganti drum lama', MaintenanceAction::find($orig->id)->action_description);
    }

    public function test_many_matching_activities_still_return_one_result_per_ticket(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f, ['title' => 'Lain']);
        foreach (range(1, 6) as $i) {
            $this->action($t, "Cek sensor ke-$i", ['performed_at' => "2026-10-10 08:0$i:00"]);
        }
        $r = $this->search($f, $f['owner'], 'sensor');
        $this->assertSame([$t->id], $this->ids($r));
        $r->assertJsonPath('meta.total', 1);
    }

    // ------------------------------------------------------ ranking & paging

    public function test_ranking_is_exact_code_then_model_then_same_machine_then_recency_then_id(): void
    {
        $f = $this->fixture();
        $at = fn (int $day) => sprintf('2026-10-%02d 08:00:00', $day);
        // All four carry the text "kompresor"; the first also carries the exact code.
        $exactOld = $this->ticket($f, ['title' => 'kompresor', 'opened_at' => $at(1)], 'machineOtherModel');
        $this->withDowntime($f, $exactOld, 'C-0213', $at(1), $at(1));
        $mention = $this->ticket($f, ['title' => 'kompresor', 'description' => 'tampil C-0213', 'opened_at' => $at(9)], 'machineMain');
        $this->withDowntime($f, $mention, null, $at(9), $at(9));

        $codeOrder = $this->ids($this->search($f, $f['owner'], 'C-0213', ['machine_id' => $f['machineMain']->id]));
        $this->assertSame([$exactOld->id, $mention->id], $codeOrder, 'exact code beats model match and recency');

        $otherModelNew = $this->ticket($f, ['title' => 'kompresor baru', 'opened_at' => $at(20)], 'machineOtherModel');
        $this->withDowntime($f, $otherModelNew, null, $at(20), $at(20));
        $sameModelOld = $this->ticket($f, ['title' => 'kompresor lama', 'opened_at' => $at(2)], 'machineSecond');
        $this->withDowntime($f, $sameModelOld, null, $at(2), $at(2));
        $sameMachineOlder = $this->ticket($f, ['title' => 'kompresor tua', 'opened_at' => $at(1)], 'machineMain');
        $this->withDowntime($f, $sameMachineOlder, null, $at(1), $at(1));

        $order = $this->ids($this->search($f, $f['owner'], 'kompresor', ['machine_id' => $f['machineMain']->id, 'per_page' => 25]));
        // model A + same machine first (newest first among them), then model A elsewhere, then other model by recency.
        $this->assertSame([$mention->id, $sameMachineOlder->id, $sameModelOld->id, $otherModelNew->id, $exactOld->id], $order);

        // Without context it is pure recency (the two day-1 incidents tie and fall back to id).
        $plain = $this->ids($this->search($f, $f['owner'], 'kompresor', ['per_page' => 25]));
        $this->assertSame([$otherModelNew->id, $mention->id, $sameModelOld->id], array_slice($plain, 0, 3));
        $this->assertEqualsCanonicalizing([$exactOld->id, $sameMachineOlder->id], array_slice($plain, 3));
    }

    public function test_pagination_is_deterministic_with_a_stable_id_tie_breaker_and_total(): void
    {
        $f = $this->fixture();
        $made = [];
        foreach (range(1, 7) as $i) {
            $made[] = $this->ticket($f, ['title' => 'seri pagination', 'opened_at' => '2026-10-10 08:00:00'])->id; // identical timestamps
        }
        $first = $this->search($f, $f['owner'], 'seri pagination', ['per_page' => 3, 'page' => 1])->assertOk()->assertJsonPath('meta.total', 7)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.per_page', 3);
        $p1 = array_column($first->json('data'), 'id');
        $p2 = $this->ids($this->search($f, $f['owner'], 'seri pagination', ['per_page' => 3, 'page' => 2]));
        $p3 = $this->ids($this->search($f, $f['owner'], 'seri pagination', ['per_page' => 3, 'page' => 3]));
        $all = array_merge($p1, $p2, $p3);
        $this->assertCount(7, array_unique($all), 'no duplicates or gaps across pages');
        $this->assertEqualsCanonicalizing($made, $all);
        $expected = $made;
        rsort($expected); // ties fall back to id DESC
        $this->assertSame($expected, $all);
        $this->assertSame($all, array_merge(
            $this->ids($this->search($f, $f['owner'], 'seri pagination', ['per_page' => 3, 'page' => 1])),
            $this->ids($this->search($f, $f['owner'], 'seri pagination', ['per_page' => 3, 'page' => 2])),
            $this->ids($this->search($f, $f['owner'], 'seri pagination', ['per_page' => 3, 'page' => 3])),
        ), 'repeatable');
        $this->assertSame([], $this->ids($this->search($f, $f['owner'], 'seri pagination', ['per_page' => 3, 'page' => 9])));
    }

    public function test_results_are_newest_incident_first_using_the_downtime_start(): void
    {
        $f = $this->fixture();
        $old = $this->ticket($f, ['title' => 'urutan waktu', 'opened_at' => '2026-10-20 08:00:00']);
        $this->withDowntime($f, $old, null, '2026-10-01 08:00:00', '2026-10-01 09:00:00'); // incident is the downtime start
        $new = $this->ticket($f, ['title' => 'urutan waktu', 'opened_at' => '2026-10-05 08:00:00']);

        $this->assertSame([$new->id, $old->id], $this->ids($this->search($f, $f['owner'], 'urutan waktu')));
    }

    public function test_query_count_does_not_grow_with_the_number_of_results(): void
    {
        $f = $this->fixture();
        $count = function () use ($f) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->search($f, $f['owner'], 'banyak hasil', ['per_page' => 25])->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        foreach (range(1, 4) as $i) {
            $this->ticket($f, ['title' => 'banyak hasil'], $i % 2 ? 'machineMain' : 'machineSecond');
        }
        $count(); // warm-up
        $few = $count();
        foreach (range(1, 16) as $i) {
            $this->ticket($f, ['title' => 'banyak hasil'], $i % 2 ? 'machineMain' : 'machineSecond');
        }
        $this->assertSame($few, $count(), 'same two branches, 5x the rows: no per-row queries');
    }

    // ----------------------------------------------------------- long text

    public function test_long_text_is_truncated_server_side_and_bounded(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f, ['title' => 'teks panjang', 'description' => str_repeat('Kertas macet di jalur. ', 600), 'resolution_cause' => str_repeat('Sebab. ', 100), 'resolution_notes' => str_repeat('n', 9000)]);
        $this->action($t, str_repeat('Langkah panjang. ', 500));

        $row = $this->search($f, $f['owner'], 'teks panjang')->assertOk()->json('data.0');
        $this->assertLessThanOrEqual(MaintenanceFieldExperienceSearch::LIST_TEXT_LIMIT + 1, mb_strlen($row['problem']));
        $this->assertStringEndsWith('…', $row['problem']);
        $this->assertTrue($row['truncated']);
        $this->assertArrayNotHasKey('notes', $row);

        $detail = $this->actingAs($f['owner'])->getJson(self::URL."/{$t->id}?account_id=".$f['account']->id)->json('data');
        $this->assertLessThanOrEqual(MaintenanceFieldExperienceSearch::DETAIL_TEXT_LIMIT + 1, mb_strlen($detail['problem']));
        $this->assertLessThanOrEqual(MaintenanceFieldExperienceSearch::DETAIL_TEXT_LIMIT + 1, mb_strlen($detail['notes']));
        $this->assertLessThanOrEqual(MaintenanceFieldExperienceSearch::DETAIL_TEXT_LIMIT + 1, mb_strlen($detail['activities'][0]['description']));
        $this->assertTrue($detail['truncated']);

        $short = $this->ticket($f, ['title' => 'teks pendek']);
        $this->assertFalse($this->search($f, $f['owner'], 'teks pendek')->json('data.0.truncated'));
    }

    // ----------------------------------------------------------- validation

    public function test_invalid_parameters_are_rejected_and_pagination_is_bounded(): void
    {
        $f = $this->fixture();
        $other = $this->ticket($f, [], 'foreignMachine');
        $ok = ['account_id' => $f['account']->id, 'q' => 'hasil'];
        $get = fn (array $p) => $this->actingAs($f['owner'])->getJson(self::URL.'?'.http_build_query($p));

        $get(['q' => 'hasil'])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $get(['account_id' => 'nope', 'q' => 'hasil'])->assertUnprocessable();
        $get(['account_id' => $f['account']->id])->assertUnprocessable()->assertJsonValidationErrors('q');
        $get(['account_id' => $f['account']->id, 'q' => 'a'])->assertUnprocessable()->assertJsonValidationErrors('q');
        $get(['account_id' => $f['account']->id, 'q' => str_repeat('a', 101)])->assertUnprocessable()->assertJsonValidationErrors('q');
        $get(['account_id' => $f['account']->id, 'q' => "hasil\x00kotor"])->assertUnprocessable()->assertJsonValidationErrors('q');
        $get(['account_id' => $f['account']->id, 'q' => 'a b c'])->assertUnprocessable()->assertJsonValidationErrors('q');
        foreach ([['per_page' => 26], ['per_page' => 0], ['page' => 0], ['page' => 1001], ['per_page' => 'x'], ['machine_id' => 'x'], ['machine_model_id' => 'x']] as $bad) {
            $get($ok + $bad)->assertUnprocessable();
        }
        $get($ok + ['machine_id' => $other->machine_id])->assertUnprocessable()->assertJsonValidationErrors('machine_id');
        $get($ok + ['machine_id' => (string) Str::uuid()])->assertUnprocessable();
        $get($ok + ['per_page' => 25, 'machine_id' => $f['machineMain']->id])->assertOk();
        $this->assertSame(10, $get($ok)->json('meta.per_page'), 'default page size');
        // A non-member is refused before parameter detail matters for another account's machine.
        $this->actingAs($f['foreigner'])->getJson(self::URL.'?'.http_build_query($ok + ['machine_id' => $f['machineMain']->id]))->assertForbidden();
    }

    public function test_interpretation_unit_rules(): void
    {
        $i = MaintenanceFieldExperienceSearch::interpret(...);
        $this->assertSame(['kind' => 'code', 'codes' => ['C-3913'], 'tokens' => []], $i('c 3913'));
        $this->assertSame(['kind' => 'code', 'codes' => ['C-C152'], 'tokens' => []], $i('cc152'));
        $this->assertSame(['kind' => 'code', 'codes' => ['J-0213'], 'tokens' => []], $i('j-0213'));
        $this->assertSame(['kind' => 'numeric', 'codes' => ['C-0213', 'J-0213'], 'tokens' => []], $i('0213'));
        $this->assertSame('text', $i('cetak')['kind'], 'ordinary words that look like C+4 letters are not codes');
        $this->assertSame('text', $i('jalan')['kind']);
        $this->assertSame(['hasil', 'kotor'], $i('Hasil  KOTOR hasil')['tokens']);
        $this->assertSame([], $i('a b')['tokens']);
        $this->assertCount(MaintenanceFieldExperienceSearch::MAX_TOKENS, $i(implode(' ', array_map(fn ($n) => "kata$n", range(1, 20))))['tokens']);
        $this->assertSame('!!!%!_', MaintenanceFieldExperienceSearch::escapeLike('!%_'));
    }
}
