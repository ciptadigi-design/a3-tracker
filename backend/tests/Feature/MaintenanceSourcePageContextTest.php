<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceDocumentPage;
use App\Models\MaintenanceKnowledgeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Maintenance V1.9 - Source Page Context. Read-only: the code-group detail view (and a tightly
 * scoped adjacent-page action) expose the FULL extracted text of a group's own supporting
 * pages from maintenance_document_pages, so a reviewer never has to bypass the product to read
 * raw page text (which is exactly what the real C-1127 review needed to do). Nothing here
 * creates, mutates or generates content; all fixtures are synthetic - no real manual text.
 */
class MaintenanceSourcePageContextTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $suffix = substr(bin2hex(random_bytes(3)), 0, 5);
        $home = Account::create(['code' => 'HOM'.$suffix, 'name' => 'Home Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRG'.$suffix, 'name' => 'Foreign Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        [$document, $import] = $this->makeImport($home);
        [$foreignDocument, $foreignImport] = $this->makeImport($foreign);

        return compact('home', 'foreign', 'document', 'import', 'foreignDocument', 'foreignImport');
    }

    /** @return array{0: MaintenanceDocument, 1: MaintenanceDocumentImport} */
    private function makeImport(Account $account): array
    {
        $document = MaintenanceDocument::create(['account_id' => $account->id, 'title' => 'Fixture Manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);
        $import = MaintenanceDocumentImport::create(['document_id' => $document->id, 'import_type' => 'PDF_EXTRACTION', 'status' => 'REVIEW', 'processing_version' => 2, 'candidate_count' => 0]);

        return [$document, $import];
    }

    private function member(Account $account, string $role = 'owner'): User
    {
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);

        return $user;
    }

    private function entry(MaintenanceDocumentImport $import, string $code, int $start, ?int $end = null, array $overrides = []): MaintenanceKnowledgeEntry
    {
        return MaintenanceKnowledgeEntry::create(array_merge([
            'import_id' => $import->id,
            'knowledge_type' => 'ERROR_CODE',
            'normalized_code' => $code,
            'code' => $code,
            'title' => 'Fixture title '.$code,
            'description' => 'Fixture excerpt for '.$code,
            'evidence' => 'HIGH',
            'collision_status' => 'NEW',
            'source_page_start' => $start,
            'source_page_end' => $end ?? $start,
            'status' => 'DRAFT',
        ], $overrides));
    }

    private function page(MaintenanceDocument $document, int $number, string $text): MaintenanceDocumentPage
    {
        return MaintenanceDocumentPage::create(['document_id' => $document->id, 'page_number' => $number, 'raw_text' => $text]);
    }

    private function detail(User $user, string $importId, string $code)
    {
        return $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$importId}/code-groups/{$code}");
    }

    private function pageContext($detailResponse): array
    {
        return collect($detailResponse->json('data.source_pages'))->keyBy('page_number')->all();
    }

    // --- 1-2. source page text is returned, with the correct page number ---

    public function test_code_group_detail_returns_the_full_text_of_its_supporting_page(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2001', 1460);
        $this->page($f['document'], 1460, "Synthetic page text for 1460.\nSecond line.");

        $detail = $this->detail($this->member($f['home']), $f['import']->id, 'C-2001')->assertOk()->json('data');
        $pages = $this->pageContext($this->detail($this->member($f['home']), $f['import']->id, 'C-2001'));

        $this->assertArrayHasKey(1460, $pages);
        $this->assertSame("Synthetic page text for 1460.\nSecond line.", $pages[1460]['raw_text']);
        $this->assertSame(1460, $pages[1460]['page_number']);
        $this->assertTrue($pages[1460]['is_primary']);
        $this->assertFalse($detail['source_pages_truncated']);
    }

    // --- 3-4. a candidate's start..end range returns every legitimate page, dedup across candidates ---

    public function test_a_multi_page_candidate_range_returns_every_page_in_the_span(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2002', 500, 502);
        $this->page($f['document'], 500, 'Page 500 text');
        $this->page($f['document'], 501, 'Page 501 text');
        $this->page($f['document'], 502, 'Page 502 text');

        $pages = $this->pageContext($this->detail($this->member($f['home']), $f['import']->id, 'C-2002'));

        $this->assertSame([500, 501, 502], array_keys($pages));
        $this->assertSame('Page 501 text', $pages[501]['raw_text']);
    }

    public function test_overlapping_ranges_from_different_candidates_in_the_same_group_are_deduplicated(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2003', 1348);
        $this->entry($f['import'], 'C-2003', 1347, 1348); // a second, overlapping occurrence also citing page 1348
        $this->entry($f['import'], 'C-2003', 1460, 1461);
        $this->page($f['document'], 1347, 'Page 1347');
        $this->page($f['document'], 1348, 'Page 1348');
        $this->page($f['document'], 1460, 'Page 1460');
        $this->page($f['document'], 1461, 'Page 1461');

        $pages = $this->pageContext($this->detail($this->member($f['home']), $f['import']->id, 'C-2003'));

        // Page 1348 is cited by two different occurrences (one directly, one via the 1347-1348
        // range) but must appear exactly once.
        $this->assertCount(4, $pages);
        $this->assertSame([1347, 1348, 1460, 1461], array_keys($pages));
    }

    // --- 5. deterministic ascending order ---

    public function test_source_pages_are_always_returned_in_ascending_page_order(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2004', 1730);
        $this->entry($f['import'], 'C-2004', 32);
        $this->entry($f['import'], 'C-2004', 949);
        foreach ([32, 949, 1730] as $n) {
            $this->page($f['document'], $n, 'Page '.$n);
        }

        $pages = $this->detail($this->member($f['home']), $f['import']->id, 'C-2004')->json('data.source_pages');

        $this->assertSame([32, 949, 1730], array_column($pages, 'page_number'));
    }

    // --- 6-7. only the group's own relevant pages, never unrelated pages ---

    public function test_pages_not_referenced_by_this_group_are_never_included(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2005', 700);
        $this->page($f['document'], 700, 'Page 700 - in the group');
        $this->page($f['document'], 701, 'Page 701 - not referenced by any C-2005 occurrence');

        $pages = $this->pageContext($this->detail($this->member($f['home']), $f['import']->id, 'C-2005'));

        $this->assertArrayHasKey(700, $pages);
        $this->assertArrayNotHasKey(701, $pages);
    }

    public function test_another_code_groups_pages_are_excluded_unless_actually_shared(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2006', 800);
        $this->entry($f['import'], 'C-2007', 801);
        $this->page($f['document'], 800, 'Page 800');
        $this->page($f['document'], 801, 'Page 801');

        $pagesFor2006 = $this->pageContext($this->detail($this->member($f['home']), $f['import']->id, 'C-2006'));

        $this->assertArrayHasKey(800, $pagesFor2006);
        $this->assertArrayNotHasKey(801, $pagesFor2006);
    }

    // --- 8-9. authorization / tenant isolation ---

    public function test_a_foreign_tenant_cannot_read_source_page_text(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2008', 900);
        $this->page($f['document'], 900, 'Private page text');

        $outsider = $this->member($f['foreign']);
        $this->detail($outsider, $f['import']->id, 'C-2008')->assertNotFound();
    }

    public function test_a_read_only_member_can_view_source_page_context(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2009', 901);
        $this->page($f['document'], 901, 'Read-only visible text');

        $reader = $this->member($f['home'], 'member');
        $pages = $this->pageContext($this->detail($reader, $f['import']->id, 'C-2009'));

        $this->assertSame('Read-only visible text', $pages[901]['raw_text']);
    }

    // --- 10. malformed/huge range is bounded, never explodes into thousands of pages ---

    public function test_an_extreme_page_range_is_bounded_rather_than_loading_thousands_of_pages(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2010', 1000, 50000); // malformed: end far beyond any real document
        $this->page($f['document'], 1000, 'Page 1000');
        $this->page($f['document'], 1005, 'Page 1005');

        $detail = $this->detail($this->member($f['home']), $f['import']->id, 'C-2010')->assertOk()->json('data');

        // Bounded to MAX_OCCURRENCE_PAGE_SPAN (20) pages from the span, well short of 49000.
        $this->assertLessThanOrEqual(21, count($detail['source_pages']));
        $this->assertContains(1000, array_column($detail['source_pages'], 'page_number'));
    }

    // --- 11. a referenced page with no actual page row is simply omitted, not an error ---

    public function test_a_candidate_referencing_a_page_with_no_stored_text_is_handled_safely(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2011', 1200); // no MaintenanceDocumentPage row created for 1200

        $detail = $this->detail($this->member($f['home']), $f['import']->id, 'C-2011')->assertOk()->json('data');

        $this->assertSame([], $detail['source_pages']);
    }

    // --- 12-13. no mutation, nothing enters governance audit ---

    public function test_reading_source_page_context_never_mutates_anything_or_writes_audit(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2012', 1300);
        $this->page($f['document'], 1300, 'Page 1300 text');
        $before = MaintenanceKnowledgeEntry::orderBy('id')->get(['id', 'status', 'updated_at'])->toArray();

        $this->detail($this->member($f['home']), $f['import']->id, 'C-2012')->assertOk();

        $this->assertSame($before, MaintenanceKnowledgeEntry::orderBy('id')->get(['id', 'status', 'updated_at'])->toArray());
        $this->assertSame(0, DB::table('machine_error_codes')->count());
        $this->assertSame(0, DB::table('maintenance_error_solutions')->count());
        $this->assertSame(0, DB::table('maintenance_document_references')->count());
        $this->assertSame(0, DB::table('governance_audit_logs')->count());
        $this->assertSame(1, MaintenanceDocumentPage::count()); // the fixture page itself, untouched
    }

    // --- 15. no N+1 regression for a multi-page group ---

    public function test_query_count_does_not_grow_per_source_page(): void
    {
        $f = $this->fixture();
        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->entry($f['import'], 'C-2013', 2000 + $n);
            $this->page($f['document'], 2000 + $n, 'Page '.(2000 + $n));
        }
        $user = $this->member($f['home']);
        $this->detail($user, $f['import']->id, 'C-2013')->assertOk(); // warm-up

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->detail($user, $f['import']->id, 'C-2013')->assertOk();
        $fiveWide = count(DB::getQueryLog());
        DB::disableQueryLog();

        $f2 = $this->fixture();
        $this->entry($f2['import'], 'C-2014', 3001);
        $this->page($f2['document'], 3001, 'Page 3001');
        $user2 = $this->member($f2['home']);
        $this->detail($user2, $f2['import']->id, 'C-2014')->assertOk(); // warm-up

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->detail($user2, $f2['import']->id, 'C-2014')->assertOk();
        $oneWide = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($oneWide, $fiveWide, 'source-page fetching must be one bulk query regardless of page count');
    }

    // --- adjacent-page endpoint ---

    private function adjacent(User $user, string $importId, string $code, int $pageNumber)
    {
        return $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$importId}/code-groups/{$code}/pages/{$pageNumber}");
    }

    public function test_an_adjacent_page_within_bound_of_a_source_page_is_readable(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2015', 1460);
        $this->page($f['document'], 1459, 'Adjacent page 1459 text');

        $data = $this->adjacent($this->member($f['home']), $f['import']->id, 'C-2015', 1459)->assertOk()->json('data');

        $this->assertSame(1459, $data['page_number']);
        $this->assertSame('Adjacent page 1459 text', $data['raw_text']);
        $this->assertFalse($data['is_direct_source']);
    }

    public function test_a_direct_source_page_is_flagged_as_such_through_the_adjacent_endpoint(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2016', 1460);
        $this->page($f['document'], 1460, 'Direct source page text');

        $data = $this->adjacent($this->member($f['home']), $f['import']->id, 'C-2016', 1460)->assertOk()->json('data');

        $this->assertTrue($data['is_direct_source']);
    }

    public function test_a_page_far_beyond_the_bound_is_refused_exactly_like_not_found(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2017', 1460);
        $this->page($f['document'], 1500, 'Far away page - not a legitimate context page');

        $this->adjacent($this->member($f['home']), $f['import']->id, 'C-2017', 1500)->assertNotFound();
    }

    public function test_adjacent_page_lookup_cannot_cross_accounts(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2018', 1460);
        $this->page($f['foreignDocument'], 1459, 'Another tenant\'s page text');

        $this->adjacent($this->member($f['home']), $f['import']->id, 'C-2018', 1459)->assertNotFound();
    }

    public function test_a_foreign_tenant_cannot_use_the_adjacent_page_endpoint_at_all(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-2019', 1460);
        $this->page($f['document'], 1459, 'Home tenant page text');

        $this->adjacent($this->member($f['foreign']), $f['import']->id, 'C-2019', 1459)->assertNotFound();
    }
}
