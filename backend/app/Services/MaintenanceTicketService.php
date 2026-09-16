<?php

namespace App\Services;

use App\Models\MaintenanceAction;
use App\Models\MaintenanceTicket;
use Illuminate\Support\Facades\DB;
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

    public function transition(MaintenanceTicket $ticket, string $status): MaintenanceTicket
    {
        return DB::transaction(function () use ($ticket, $status) {
            $ticket = MaintenanceTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $allowed = self::TRANSITIONS[$ticket->status] ?? [];
            if (! in_array($status, $allowed, true)) {
                throw new ConflictHttpException("cannot transition maintenance ticket from {$ticket->status} to {$status}");
            }
            $updates = ['status' => $status];
            if ($status === 'IN_PROGRESS' && ! $ticket->started_at) {
                $updates['started_at'] = now();
            }
            if (in_array($status, ['DONE', 'CANCELLED'], true)) {
                // Downtime is opened_at -> resolved_at; setting resolved_at here IS closing
                // the downtime window, no separate restoration bookkeeping is needed.
                $updates['resolved_at'] = now();
            }
            $ticket->update($updates);

            return $ticket;
        });
    }

    public function recordAction(MaintenanceTicket $ticket, array $d): MaintenanceAction
    {
        return MaintenanceAction::create($d + ['account_id' => $ticket->account_id, 'ticket_id' => $ticket->id]);
    }
}
