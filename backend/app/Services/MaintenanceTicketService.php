<?php

namespace App\Services;

use App\Models\MaintenanceAction;
use App\Models\MaintenanceTicket;
use App\Models\OperationalPerson;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MaintenanceTicketService
{
    private const TRANSITIONS = [
        'OPEN' => ['IN_PROGRESS', 'CANCELLED'],
        'IN_PROGRESS' => ['DONE', 'CANCELLED'],
        'DONE' => [],
        'CANCELLED' => [],
    ];

    public function create(array $d): MaintenanceTicket
    {
        if (! empty($d['client_request_id'])) {
            $existing = MaintenanceTicket::where('account_id', $d['account_id'])->where('client_request_id', $d['client_request_id'])->first();
            if ($existing) {
                return $existing;
            }
        }

        // Set explicitly rather than relying on the DB column default - Eloquent's
        // in-memory model after create() only reflects attributes it was given, not
        // defaults applied by the database, so the caller's immediate response would
        // otherwise be missing `status`/`type`/`priority` until a fresh() reload.
        return MaintenanceTicket::create($d + ['status' => 'OPEN', 'type' => 'breakdown', 'priority' => 'normal']);
    }

    public const TEST_RESULTS = ['normal', 'monitoring', 'not_normal'];

    /**
     * Complete (or otherwise transition) a ticket. Resolution, completion
     * timestamp/user and status are written in one transaction, so a rejected
     * resolution can never leave a ticket half-closed. Linked downtime is
     * deliberately never touched here.
     */
    public function transition(MaintenanceTicket $ticket, string $status, array $resolution = [], ?User $actor = null): MaintenanceTicket
    {
        return DB::transaction(function () use ($ticket, $status, $resolution, $actor) {
            $ticket = MaintenanceTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $allowed = self::TRANSITIONS[$ticket->status] ?? [];
            if (! in_array($status, $allowed, true)) {
                throw new ConflictHttpException("cannot transition maintenance ticket from {$ticket->status} to {$status}");
            }
            $updates = ['status' => $status];
            if ($status === 'DONE') {
                $updates += $this->validatedResolution($ticket, $resolution);
            }
            if ($status === 'IN_PROGRESS' && ! $ticket->started_at) {
                $updates['started_at'] = now();
            }
            if (in_array($status, ['DONE', 'CANCELLED'], true)) {
                // Downtime is opened_at -> resolved_at; setting resolved_at here IS closing
                // the downtime window, no separate restoration bookkeeping is needed.
                $updates['resolved_at'] = now();
                $updates['resolved_by'] = $actor?->id;
            }
            $ticket->update($updates);

            return $ticket;
        });
    }

    public function recordAction(MaintenanceTicket $ticket, array $d): MaintenanceAction
    {
        $person = null;
        if (! empty($d['operator_person_id'])) {
            $person = $this->eligiblePerson($ticket, $d['operator_person_id'], 'operator_person_id');
        }
        $external = $this->blankToNull($d['external_technician_name'] ?? null);
        $vendor = $this->blankToNull($d['vendor_name'] ?? null);
        if (! $person && $external === null && $vendor === null) {
            // No performer given: inherit the ticket's current responsible people.
            $person = $ticket->internal_pic_person_id ? OperationalPerson::where('account_id', $ticket->account_id)->find($ticket->internal_pic_person_id) : null;
            $external = $ticket->external_technician_name;
            $vendor = $ticket->external_vendor_name;
        }

        return MaintenanceAction::create([
            'account_id' => $ticket->account_id,
            'ticket_id' => $ticket->id,
            'action_description' => $d['action_description'],
            'findings' => $this->blankToNull($d['findings'] ?? null),
            'result' => $d['result'] ?? null,
            'component_replacement_id' => $d['component_replacement_id'] ?? null,
            'performed_by' => $d['performed_by'] ?? null,
            'performed_at' => $d['performed_at'] ?? null,
            'operator_person_id' => $person?->id,
            'operator_name' => $person?->name,
            'external_technician_name' => $external,
            'vendor_name' => $vendor,
        ]);
    }

    /**
     * Breakdown tickets must carry a complete resolution and cannot be closed
     * while the test result is "not_normal". Preventive/inspection tickets keep
     * their prior behaviour (resolution optional), but a partial resolution is
     * still rejected rather than stored half-filled.
     */
    private function validatedResolution(MaintenanceTicket $ticket, array $r): array
    {
        $cause = trim((string) ($r['resolution_cause'] ?? ''));
        $action = trim((string) ($r['resolution_action'] ?? ''));
        $result = $r['resolution_test_result'] ?? null;
        $notes = isset($r['resolution_notes']) ? trim((string) $r['resolution_notes']) : null;
        $provided = $cause !== '' || $action !== '' || $result !== null;

        if ($ticket->type !== 'breakdown' && ! $provided) {
            return [];
        }

        $errors = [];
        if ($cause === '') {
            $errors['resolution_cause'] = 'Penyebab masalah wajib diisi.';
        }
        if ($action === '') {
            $errors['resolution_action'] = 'Tindakan penyelesaian wajib diisi.';
        }
        if (! in_array($result, self::TEST_RESULTS, true)) {
            $errors['resolution_test_result'] = 'Hasil pengujian wajib dipilih.';
        } elseif ($result === 'not_normal' && $ticket->type === 'breakdown') {
            $errors['resolution_test_result'] = 'Mesin belum normal. Tiket gangguan tidak dapat diselesaikan.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'resolution_cause' => $cause,
            'resolution_action' => $action,
            'resolution_test_result' => $result,
            'resolution_notes' => $notes === '' ? null : $notes,
        ];
    }

    /** An active person assigned to the ticket's own branch, within its own account. */
    public function eligiblePerson(MaintenanceTicket $ticket, string $personId, string $field): OperationalPerson
    {
        $person = OperationalPerson::where('account_id', $ticket->account_id)
            ->where('is_active', true)
            ->whereKey($personId)
            ->whereHas('branchAssignments', fn ($q) => $q->where('branch_id', $ticket->branch_id)->where('is_active', true))
            ->first();
        if (! $person) {
            throw ValidationException::withMessages([$field => 'Operator tidak tersedia untuk cabang tiket ini.']);
        }

        return $person;
    }

    public function setResponsible(MaintenanceTicket $ticket, array $d): MaintenanceTicket
    {
        $person = ! empty($d['internal_pic_person_id']) ? $this->eligiblePerson($ticket, $d['internal_pic_person_id'], 'internal_pic_person_id') : null;
        $ticket->update([
            'internal_pic_person_id' => $person?->id,
            'internal_pic_name' => $person?->name,
            'external_technician_name' => $this->blankToNull($d['external_technician_name'] ?? null),
            'external_vendor_name' => $this->blankToNull($d['external_vendor_name'] ?? null),
        ]);

        return $ticket;
    }

    /**
     * Auditable correction: a new row referencing the original activity, with a reason and the
     * submitting user. The original, the ticket status and any linked downtime are untouched.
     */
    public function correctAction(MaintenanceTicket $ticket, MaintenanceAction $original, array $d): MaintenanceAction
    {
        if ($original->ticket_id !== $ticket->id || $original->account_id !== $ticket->account_id) {
            throw ValidationException::withMessages(['action' => 'Aktivitas tidak ditemukan pada tiket ini.']);
        }
        if ($original->corrects_action_id !== null) {
            throw ValidationException::withMessages(['action' => 'Koreksi hanya dapat dibuat untuk aktivitas asli.']);
        }
        $person = ! empty($d['operator_person_id']) ? $this->eligiblePerson($ticket, $d['operator_person_id'], 'operator_person_id') : null;
        $external = $this->blankToNull($d['external_technician_name'] ?? null);
        $vendor = $this->blankToNull($d['vendor_name'] ?? null);
        if (! $person && $external === null && $vendor === null) {
            // No performer given: the correction keeps the original's performer.
            $person = $original->operator_person_id ? OperationalPerson::where('account_id', $ticket->account_id)->find($original->operator_person_id) : null;
            $external = $original->external_technician_name;
            $vendor = $original->vendor_name;
        }

        return MaintenanceAction::create([
            'account_id' => $ticket->account_id,
            'ticket_id' => $ticket->id,
            'corrects_action_id' => $original->id,
            'correction_reason' => trim($d['correction_reason']),
            'action_description' => $d['action_description'],
            'findings' => $this->blankToNull($d['findings'] ?? null),
            'performed_by' => $d['performed_by'] ?? null,
            'operator_person_id' => $person?->id,
            'operator_name' => $person?->name,
            'external_technician_name' => $external,
            'vendor_name' => $vendor,
        ]);
    }

    private function blankToNull(?string $v): ?string
    {
        $v = $v === null ? null : trim($v);

        return $v === '' ? null : $v;
    }
}
