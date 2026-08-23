<?php

namespace App\Services;

use App\Models\GarageBooking;
use App\Models\ServiceHistory;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Support\Facades\DB;

class GarageWorkflowService
{
    public function __construct(private AuditLogger $audit) {}

    /** Map booking status → work order status. */
    public function mapBookingToWorkOrderStatus(string $bookingStatus): string
    {
        return match ($bookingStatus) {
            'pending', 'confirmed' => 'open',
            'assigned' => 'assigned',
            'in_progress' => 'in_progress',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => 'open',
        };
    }

    public function ensureWorkOrder(GarageBooking $booking): WorkOrder
    {
        $booking->loadMissing(['service', 'garage']);

        $wo = WorkOrder::firstOrCreate(
            ['garage_booking_id' => $booking->id],
            [
                'business_id' => $booking->business_id,
                'garage_id' => $booking->garage_id,
                'customer_id' => $booking->customer_id,
                'technician_id' => $booking->technician_id,
                'service_id' => $booking->service_id,
                'vehicle_reg' => $booking->vehicle_reg,
                'status' => $this->mapBookingToWorkOrderStatus($booking->status),
                'notes' => $booking->notes,
                'total_amount' => $booking->amount,
            ]
        );

        return $wo;
    }

    public function syncFromBooking(GarageBooking $booking, ?string $previousStatus = null): WorkOrder
    {
        $wo = $this->ensureWorkOrder($booking);

        $status = $this->mapBookingToWorkOrderStatus($booking->status);
        $updates = [
            'technician_id' => $booking->technician_id,
            'service_id' => $booking->service_id,
            'vehicle_reg' => $booking->vehicle_reg,
            'notes' => $booking->notes,
            'status' => $status,
        ];

        if ($status === 'in_progress' && ! $wo->started_at) {
            $updates['started_at'] = now();
        }

        if ($status === 'completed') {
            $updates['completed_at'] = $wo->completed_at ?? now();
            if ($booking->amount !== null) {
                $updates['total_amount'] = $booking->amount;
            }
        }

        if ($status === 'cancelled') {
            $updates['completed_at'] = null;
        }

        $old = $wo->only(['status', 'technician_id', 'vehicle_reg']);
        $wo->update($updates);

        if ($previousStatus !== $booking->status) {
            $this->audit->log('work_order.status_changed', $wo, $old, [
                'status' => $wo->status,
                'booking_status' => $booking->status,
            ]);
        }

        if ($status === 'completed') {
            $this->recordServiceHistory($wo->fresh(['service', 'booking']));
            if ($wo->status !== 'closed') {
                $wo->update(['status' => 'closed']);
            }
        }

        return $wo->fresh(['items', 'service', 'technician.user']);
    }

    public function recordServiceHistory(WorkOrder $workOrder): ServiceHistory
    {
        $existing = ServiceHistory::where('work_order_id', $workOrder->id)->first();
        if ($existing) {
            return $existing;
        }

        $vehicleId = null;
        if ($workOrder->vehicle_reg) {
            $vehicleId = Vehicle::where('vehicle_number', $workOrder->vehicle_reg)->value('id');
        }

        $history = ServiceHistory::create([
            'vehicle_reg' => $workOrder->vehicle_reg,
            'vehicle_id' => $vehicleId,
            'garage_id' => $workOrder->garage_id,
            'work_order_id' => $workOrder->id,
            'garage_booking_id' => $workOrder->garage_booking_id,
            'customer_id' => $workOrder->customer_id,
            'service_name' => $workOrder->service?->name,
            'summary' => $workOrder->diagnosis ?: $workOrder->notes,
            'amount' => $workOrder->total_amount ?? $workOrder->booking?->amount,
            'serviced_at' => $workOrder->completed_at ?? now(),
        ]);

        $this->audit->log('service_history.created', $history, null, $history->toArray());

        return $history;
    }

    public function addItem(WorkOrder $workOrder, array $data): WorkOrderItem
    {
        $qty = (float) ($data['quantity'] ?? 1);
        $price = (float) ($data['unit_price'] ?? 0);

        $item = $workOrder->items()->create([
            'item_type' => $data['item_type'] ?? 'labour',
            'description' => $data['description'],
            'quantity' => $qty,
            'unit_price' => $price,
            'line_total' => round($qty * $price, 2),
        ]);

        $workOrder->recalculateTotals();
        $this->audit->log('work_order.item_added', $workOrder, null, $item->toArray());

        return $item;
    }

    public function start(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {
            $workOrder->update([
                'status' => 'in_progress',
                'started_at' => $workOrder->started_at ?? now(),
            ]);

            if ($workOrder->booking && $workOrder->booking->status !== 'in_progress') {
                $workOrder->booking->update(['status' => 'in_progress']);
            }

            $this->audit->log('work_order.started', $workOrder);

            return $workOrder->fresh(['items', 'booking', 'technician.user']);
        });
    }

    public function complete(WorkOrder $workOrder, ?string $diagnosis = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $diagnosis) {
            $updates = [
                'status' => 'completed',
                'completed_at' => now(),
            ];
            if ($diagnosis !== null) {
                $updates['diagnosis'] = $diagnosis;
            }
            $workOrder->update($updates);

            if ($workOrder->booking && $workOrder->booking->status !== 'completed') {
                $bookingUpdates = ['status' => 'completed'];
                if ($workOrder->total_amount !== null) {
                    $bookingUpdates['amount'] = $workOrder->total_amount;
                }
                $workOrder->booking->update($bookingUpdates);
            }

            $this->recordServiceHistory($workOrder->fresh(['service', 'booking']));
            $workOrder->update(['status' => 'closed']);
            $this->audit->log('work_order.completed', $workOrder);

            return $workOrder->fresh(['items', 'serviceHistory', 'booking']);
        });
    }
}
