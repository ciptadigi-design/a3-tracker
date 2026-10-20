<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MaintenanceAction;
use App\Models\User;
use App\Services\KnowledgeProcessing\ErrorCodeNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Pengalaman Lapangan: read-only search over maintenance tickets that finished a
 * real repair. Nothing is copied or published - a "field experience" is simply an
 * eligible ticket, projected on the fly, so it can never drift from the ticket.
 *
 * Eligibility (all required): type breakdown, status DONE, resolution cause and
 * action of at least MIN_RESOLUTION_CHARS trimmed characters, and a test result of
 * normal or monitoring. Everything else (open, in progress, cancelled, preventive,
 * inspection, incomplete or "not_normal") is excluded by the query itself.
 *
 * Every query is bound to one account. Cross-account rows cannot be returned: the
 * account predicate is on the ticket and repeated on every joined table.
 *
 * Code resolution order for display is official reference > account error-code
 * reference > the code typed on the linked downtime; a code query matches any of the
 * three. The ticket's own error_code_id is NOT assumed to carry the downtime's code.
 */
final class MaintenanceFieldExperienceSearch
{
    public const MIN_RESOLUTION_CHARS = 5;

    public const ELIGIBLE_TEST_RESULTS = ['normal', 'monitoring'];

    public const MAX_TOKENS = 8;

    public const LIST_TEXT_LIMIT = 240;

    public const DETAIL_TEXT_LIMIT = 4000;

    public const DETAIL_ACTIVITY_LIMIT = 50;

    private const LIKE_ESCAPE = '!';

    public function __construct(private BranchAccessResolver $branches, private DowntimeDurationCalculator $durations) {}

    /**
     * Classify the raw query. C-/J- codes keep their family; a bare 4-digit number
     * is ambiguous and is searched in both families without merging them.
     *
     * @return array{kind: string, codes: list<string>, tokens: list<string>}
     */
    public static function interpret(string $raw): array
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $compact = strtoupper((string) preg_replace('/[\s-]+/u', '', $clean));

        if (preg_match('/^\d{4}$/', $compact) === 1) {
            return ['kind' => 'numeric', 'codes' => ['C-'.$compact, 'J-'.$compact], 'tokens' => []];
        }
        // Same C/J + 4 characters shape as the official codes (C-3913, C-C152); at least one digit
        // keeps ordinary words such as "cetak" or "jalan" from being read as codes.
        if (preg_match('/^([CJ])([A-Z0-9]{4})$/', $compact, $m) === 1 && preg_match('/\d/', $m[2]) === 1) {
            return ['kind' => 'code', 'codes' => [$m[1].'-'.$m[2]], 'tokens' => []];
        }

        $tokens = [];
        foreach (explode(' ', mb_strtolower($clean)) as $token) {
            if (mb_strlen($token) >= 2 && ! in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return ['kind' => 'text', 'codes' => [], 'tokens' => array_slice($tokens, 0, self::MAX_TOKENS)];
    }

    public static function escapeLike(string $value): string
    {
        return str_replace([self::LIKE_ESCAPE, '%', '_'], [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'], $value);
    }

    /** @param array{kind: string, codes: list<string>, tokens: list<string>} $query */
    public function search(User $user, string $accountId, array $query, ?string $machineId, ?string $modelId, int $perPage, int $page): array
    {
        $builder = $this->eligibleTickets($accountId);

        [$scoreSql, $scoreBindings, $whereSql, $whereBindings] = $this->matching($query);
        $builder->whereRaw($whereSql, $whereBindings);

        $builder->selectRaw(
            $this->columns().', '.$scoreSql.' as match_score, '
            .($modelId ? 'CASE WHEN m.machine_model_id = ? THEN 1 ELSE 0 END' : '0').' as model_match, '
            .($machineId ? 'CASE WHEN t.machine_id = ? THEN 1 ELSE 0 END' : '0').' as same_machine, '
            .'COALESCE(d.actual_start_at, t.opened_at) as incident_at',
            array_merge($scoreBindings, $modelId ? [$modelId] : [], $machineId ? [$machineId] : []),
        );
        // Deterministic: exact code, then model, then same machine, then newest, then id.
        $builder->orderByRaw('match_score DESC, model_match DESC, same_machine DESC, incident_at DESC, t.id DESC');

        /** @var LengthAwarePaginator $paginator */
        $paginator = $builder->paginate($perPage, ['*'], 'page', $page);
        $rows = collect($paginator->items());
        $open = $this->canOpenMap($user, $rows->pluck('branch_id')->unique()->all());

        return [
            'data' => $rows->map(fn ($row) => $this->present($row, $open[$row->branch_id] ?? false, false, $query['kind']))->all(),
            'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ];
    }

    public function find(User $user, string $accountId, string $ticketId): ?array
    {
        $row = $this->eligibleTickets($accountId)
            ->where('t.id', $ticketId)
            ->selectRaw($this->columns().', COALESCE(d.actual_start_at, t.opened_at) as incident_at, 0 as match_score, 0 as model_match, 0 as same_machine')
            ->first();
        if (! $row) {
            return null;
        }
        $canOpen = $this->canOpenMap($user, [$row->branch_id])[$row->branch_id] ?? false;
        $data = $this->present($row, $canOpen, true, 'detail');
        $data['activities'] = $this->activities($accountId, $ticketId, $canOpen);

        return $data;
    }

    /** Eligible tickets of one account with every display join; the single place eligibility is defined. */
    private function eligibleTickets(string $accountId): Builder
    {
        $len = DB::connection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';

        return DB::table('maintenance_tickets as t')
            ->join('machines as m', fn (JoinClause $j) => $j->on('m.id', '=', 't.machine_id')->on('m.account_id', '=', 't.account_id'))
            ->join('branches as b', fn (JoinClause $j) => $j->on('b.id', '=', 't.branch_id')->on('b.account_id', '=', 't.account_id'))
            ->leftJoin('machine_models as mm', 'mm.id', '=', 'm.machine_model_id')
            ->leftJoin('downtime_events as d', fn (JoinClause $j) => $j->on('d.id', '=', 't.downtime_event_id')->on('d.account_id', '=', 't.account_id'))
            ->leftJoin('machine_error_codes as ec', fn (JoinClause $j) => $j->on('ec.id', '=', 't.error_code_id')
                ->where(fn ($w) => $w->whereNull('ec.account_id')->orWhere('ec.account_id', $accountId)))
            ->leftJoin('maintenance_official_error_entries as oe', 'oe.id', '=', 't.official_error_entry_id')
            ->leftJoin('maintenance_documents as od', fn (JoinClause $j) => $j->on('od.id', '=', 'oe.document_id')
                ->where(fn ($w) => $w->whereNull('od.account_id')->orWhere('od.account_id', $accountId)))
            ->where('t.account_id', $accountId)
            ->where('t.type', 'breakdown')
            ->where('t.status', 'DONE')
            ->whereIn('t.resolution_test_result', self::ELIGIBLE_TEST_RESULTS)
            ->whereRaw("$len(TRIM(t.resolution_cause)) >= ?", [self::MIN_RESOLUTION_CHARS])
            ->whereRaw("$len(TRIM(t.resolution_action)) >= ?", [self::MIN_RESOLUTION_CHARS]);
    }

    private function columns(): string
    {
        return 't.id, t.branch_id, t.title, t.description, t.resolution_cause, t.resolution_action, t.resolution_notes, t.resolution_test_result, '
            .'t.internal_pic_name, t.external_technician_name, t.external_vendor_name, t.opened_at, '
            .'m.display_name as machine_name, m.machine_code, mm.name as model_name, b.name as branch_name, '
            .'d.actual_start_at as downtime_start, d.actual_end_at as downtime_end, '
            .'CASE WHEN od.id IS NOT NULL THEN oe.code END as official_code, CASE WHEN od.id IS NOT NULL THEN oe.id END as official_entry_id, ec.code as account_code, d.error_code_text as downtime_code';
    }

    /**
     * @param  array{kind: string, codes: list<string>, tokens: list<string>}  $query
     * @return array{0: string, 1: list<string>, 2: string, 3: list<string>} score SQL, bindings, WHERE SQL, bindings
     */
    private function matching(array $query): array
    {
        if ($query['kind'] === 'text') {
            $sql = [];
            $bindings = [];
            foreach ($query['tokens'] as $token) {
                [$part, $b] = $this->textMatch('%'.self::escapeLike($token).'%');
                $sql[] = $part;
                array_push($bindings, ...$b);
            }

            return ['0', [], $sql ? implode(' AND ', $sql) : '1 = 0', $bindings];
        }

        // Code / numeric: exact on any of the three code sources, or the code written in the ticket text.
        $compact = fn (string $expr) => "REPLACE(REPLACE(UPPER($expr), '-', ''), ' ', '')";
        $codes = array_map(fn (string $c) => str_replace('-', '', $c), $query['codes']);
        $in = implode(',', array_fill(0, count($codes), '?'));
        $exact = '('.implode(' OR ', [
            $compact('CASE WHEN od.id IS NOT NULL THEN oe.code END')." IN ($in)",
            $compact('ec.code')." IN ($in)",
            $compact('d.error_code_text')." IN ($in)",
        ]).')';
        $exactBindings = array_merge($codes, $codes, $codes);

        $mentions = [];
        $mentionBindings = [];
        foreach ($query['codes'] as $code) {
            foreach ([$code, str_replace('-', '', $code)] as $form) {
                [$part, $b] = $this->textMatch('%'.self::escapeLike($form).'%');
                $mentions[] = $part;
                array_push($mentionBindings, ...$b);
            }
        }
        $mention = '('.implode(' OR ', $mentions).')';

        return [
            "CASE WHEN $exact THEN 2 WHEN $mention THEN 1 ELSE 0 END",
            array_merge($exactBindings, $mentionBindings),
            "($exact OR $mention)",
            array_merge($exactBindings, $mentionBindings),
        ];
    }

    /**
     * One LIKE pattern against the ticket text and the effective activity text.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function textMatch(string $pattern): array
    {
        $like = fn (string $col) => "$col LIKE ? ESCAPE '".self::LIKE_ESCAPE."'";
        $fields = ['t.title', 't.description', 't.resolution_cause', 't.resolution_action', 't.resolution_notes'];
        $sql = '('.implode(' OR ', array_map($like, $fields))
            .' OR EXISTS (SELECT 1 FROM maintenance_actions a WHERE a.ticket_id = t.id AND a.account_id = t.account_id AND '.$this->effectiveActivity('a')
            .' AND ('.$like('a.action_description').' OR '.$like('a.findings').')))';

        return [$sql, array_fill(0, count($fields) + 2, $pattern)];
    }

    /**
     * Correction semantics of the activity log: corrections are new rows pointing at the
     * original, and the latest correction is the "current" content. An activity is effective
     * when nothing supersedes it, so a superseded original or older correction is never
     * searched or shown as a separate experience (ties on time fall back to id).
     */
    private function effectiveActivity(string $a): string
    {
        return "(($a.corrects_action_id IS NULL AND NOT EXISTS (SELECT 1 FROM maintenance_actions c1 WHERE c1.corrects_action_id = $a.id))"
            ." OR ($a.corrects_action_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM maintenance_actions c2 WHERE c2.corrects_action_id = $a.corrects_action_id"
            ." AND (c2.performed_at > $a.performed_at OR (c2.performed_at = $a.performed_at AND c2.id > $a.id)))))";
    }

    /** Same rule as opening the ticket (MachineAccessResolver -> BranchAccessResolver), once per distinct branch. */
    private function canOpenMap(User $user, array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }
        $map = [];
        foreach (Branch::with('account')->whereIn('id', $branchIds)->get() as $branch) {
            $map[$branch->id] = $this->branches->canAccess($user, $branch);
        }

        return $map;
    }

    private function activities(string $accountId, string $ticketId, bool $showInternal): array
    {
        $rows = MaintenanceAction::query()
            ->where('account_id', $accountId)->where('ticket_id', $ticketId)
            ->orderBy('performed_at')->orderBy('id')->get();

        $latest = [];
        foreach ($rows->whereNotNull('corrects_action_id') as $correction) {
            $latest[$correction->corrects_action_id] = $correction; // oldest -> newest: the last one wins
        }

        return $rows->whereNull('corrects_action_id')->take(self::DETAIL_ACTIVITY_LIMIT)->map(function ($original) use ($latest, $showInternal) {
            $current = $latest[$original->id] ?? $original;

            return [
                'performed_at' => $original->performed_at?->utc()->toIso8601ZuluString(),
                'description' => self::limit($current->action_description, self::DETAIL_TEXT_LIMIT),
                'findings' => self::limit($current->findings, self::DETAIL_TEXT_LIMIT),
                'result' => self::limit($current->result, 80),
                'operator_name' => $showInternal ? $current->operator_name : null,
                'external_technician_name' => $showInternal ? $current->external_technician_name : null,
                'vendor_name' => $showInternal ? $current->vendor_name : null,
                'corrected' => isset($latest[$original->id]),
            ];
        })->values()->all();
    }

    private function present(object $row, bool $canOpen, bool $detail, string $kind): array
    {
        $limit = $detail ? self::DETAIL_TEXT_LIMIT : self::LIST_TEXT_LIMIT;
        $raw = $row->official_code ?: ($row->account_code ?: (trim((string) $row->downtime_code) !== '' ? $row->downtime_code : null));
        $source = $row->official_code ? 'official' : ($row->account_code ? 'account' : ($raw !== null ? 'downtime' : null));
        $truncated = false;
        $cut = function (?string $value) use ($limit, &$truncated) {
            $text = self::limit($value, $limit);
            $truncated = $truncated || ($value !== null && $text !== trim($value));

            return $text;
        };
        $minutes = null;
        if ($row->downtime_start && $row->downtime_end) {
            $minutes = $this->durations->elapsedMinutes(CarbonImmutable::parse($row->downtime_start), CarbonImmutable::parse($row->downtime_end));
        }

        $data = [
            'id' => $row->id,
            'title' => self::limit($row->title, 200),
            'problem' => $cut($row->description),
            'error_code' => $raw === null ? null : (ErrorCodeNormalizer::normalize($raw) ?? strtoupper(trim($raw))),
            'error_code_source' => $source,
            'machine_name' => $row->machine_name,
            'machine_code' => $row->machine_code,
            'model_name' => $row->model_name,
            'branch_name' => $row->branch_name,
            'incident_at' => CarbonImmutable::parse($row->incident_at)->utc()->toIso8601ZuluString(),
            'downtime_minutes' => $minutes,
            'cause' => $cut($row->resolution_cause),
            'action' => $cut($row->resolution_action),
            'test_result' => $row->resolution_test_result,
            // Named people and vendors are returned only to those who may open the ticket (list and detail alike).
            'external_technician_name' => $canOpen ? $row->external_technician_name : null,
            'external_vendor_name' => $canOpen ? $row->external_vendor_name : null,
            'internal_pic_name' => $canOpen ? $row->internal_pic_name : null,
            'can_open' => $canOpen,
            'matched_by' => (int) $row->match_score === 2 ? 'code' : ((int) $row->match_score === 1 ? 'code_in_text' : ($kind === 'text' ? 'text' : null)),
            'model_match' => (bool) $row->model_match,
            'same_machine' => (bool) $row->same_machine,
        ];
        if ($detail) {
            $data['notes'] = $cut($row->resolution_notes);
            // Only an explicit link from the ticket to a visible official entry; never inferred from matching codes.
            $data['official_reference'] = $row->official_entry_id ? ['entry_id' => $row->official_entry_id, 'code' => $row->official_code] : null;
        }
        $data['truncated'] = $truncated;

        return $data;
    }

    private static function limit(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max)).'…' : $value;
    }
}
