<?php

namespace App\Services;

use App\Models\DowntimeEvent;
use App\Models\DowntimeNote;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Downtime is a distinct concept from maintenance.MaintenanceTicket's own
 * opened_at/resolved_at is server-set-only by design (see that migration's
 * comment); downtime_events follows the same "never let a client fabricate
 * elapsed time independent of a real lifecycle timestamp" principle, except
 * that actual_start_at/actual_end_at ARE client-suppliable (backdatable) -
 * that is new, explicitly-audited territory gated behind the
 * `downtime.backdate` capability, checked by the caller before this service
 * is invoked.
 */
class DowntimeService
{
    // How much slack before a start time counts as "backdated" - normal
    // network/human entry delay, not a meaningful retroactive edit.
    private const BACKDATE_TOLERANCE_MINUTES = 5;

    private const CAUSE_LABELS = [
        'paper_jam' => 'Paper Jam',
        'layar_blank' => 'Layar Blank',
        'hasil_cetak_tidak_optimal' => 'Hasil cetak tidak optimal',
        'hasil_kotor' => 'Hasil Kotor',
        'hasil_pudar' => 'Hasil Pudar',
        'hasil_berbayang' => 'Hasil Berbayang',
        'hasil_bergaris' => 'Hasil Bergaris',
        'hasil_tidak_menempel' => 'Hasil Tidak Menempel',
        'mesin_berisik' => 'Mesin berisik',
        'toner_tidak_detect' => 'Toner tidak detect',
        'mesin_mati_total' => 'Mesin Mati Total',
        'layar_berubah_warna' => 'Layar Berubah Warna',
        'error_code' => 'Error code',
        'mechanical' => 'Kerusakan Mekanis',
        'consumable' => 'Consumable Habis',
        'scheduled_maintenance' => 'Maintenance Terjadwal',
        'power' => 'Gangguan Listrik',
        'other' => 'Lainnya',
        'jam' => 'Paper Jam',
    ];

    public function __construct(private MaintenanceTicketService $tickets) {}

    public function start(Machine $machine, User $actor, array $d, bool $canBackdate): DowntimeEvent
    {
        if (! empty($d['client_request_id'])) {
            $existing = DowntimeEvent::where('account_id', $machine->account_id)->where('client_request_id', $d['client_request_id'])->with('maintenanceTicket')->first();
            if ($existing) {
                return $existing;
            }
        }

        $actualStartAt = ! empty($d['actual_start_at']) ? Carbon::parse($d['actual_start_at']) : now();
        $isBackdated = $actualStartAt->lt(now()->subMinutes(self::BACKDATE_TOLERANCE_MINUTES));
        if ($isBackdated && ! $canBackdate) {
            throw ValidationException::withMessages(['actual_start_at' => 'Only an authorized role may record a backdated downtime start time.']);
        }
        if ($actualStartAt->gt(now()->addMinute())) {
            throw ValidationException::withMessages(['actual_start_at' => 'Downtime cannot start in the future.']);
        }

        try {
            return DB::transaction(function () use ($machine, $actor, $d, $actualStartAt, $isBackdated) {
                $event = DowntimeEvent::create([
                    'account_id' => $machine->account_id,
                    'branch_id' => $machine->branch_id,
                    'machine_id' => $machine->id,
                    'cause' => $d['cause'],
                    'error_code_text' => $d['error_code_text'] ?? null,
                    'description' => $d['description'] ?? null,
                    'actual_start_at' => $actualStartAt,
                    'created_by' => $actor->id,
                    'is_backdated' => $isBackdated,
                    'active_key' => 'active',
                    'client_request_id' => $d['client_request_id'] ?? null,
                ]);

                $ticket = $this->tickets->create([
                    'account_id' => $event->account_id,
                    'branch_id' => $event->branch_id,
                    'machine_id' => $event->machine_id,
                    'downtime_event_id' => $event->id,
                    'title' => self::CAUSE_LABELS[$event->cause] ?? str_replace('_', ' ', ucfirst($event->cause)),
                    'description' => $event->description,
                    'reported_by' => $actor->id,
                    'opened_at' => $event->actual_start_at,
                ]);
                $event->setRelation('maintenanceTicket', $ticket);

                return $event;
            }, 3);
        } catch (QueryException $e) {
            // A retry racing the original request resolves to the already committed
            // pair. A different request still receives the active-event conflict.
            if (! empty($d['client_request_id'])) {
                $existing = DowntimeEvent::where('account_id', $machine->account_id)
                    ->where('client_request_id', $d['client_request_id'])
                    ->with('maintenanceTicket')
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }
            if ((string) $e->getCode() === '23000') {
                throw new ConflictHttpException('This machine already has an active downtime event. End it before starting a new one.');
            }
            throw $e;
        }
    }

    public function addNote(DowntimeEvent $event, array $d): DowntimeNote
    {
        if (! empty($d['client_request_id'])) {
            $existing = DowntimeNote::where('account_id', $event->account_id)->where('client_request_id', $d['client_request_id'])->first();
            if ($existing) {
                return $existing;
            }
        }

        // Adding a note never closes (or reopens) the downtime window - downtime
        // only closes via an explicit End Downtime action (see end() below).
        return DowntimeNote::create($d + [
            'account_id' => $event->account_id,
            'downtime_event_id' => $event->id,
        ]);
    }

    public function end(DowntimeEvent $event, User $actor, array $d): DowntimeEvent
    {
        return DB::transaction(function () use ($event, $actor, $d) {
            $event = DowntimeEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if (! $event->isActive()) {
                throw new ConflictHttpException('This downtime event is already closed.');
            }
            $actualEndAt = ! empty($d['actual_end_at']) ? Carbon::parse($d['actual_end_at']) : now();
            if ($actualEndAt->lt($event->actual_start_at)) {
                throw ValidationException::withMessages(['actual_end_at' => 'End time cannot be before the start time.']);
            }
            $event->update([
                'actual_end_at' => $actualEndAt,
                'resolution_note' => $d['resolution_note'] ?? null,
                'closed_by' => $actor->id,
                'active_key' => null,
            ]);

            return $event;
        });
    }
}
