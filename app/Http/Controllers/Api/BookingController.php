<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    public function myBookings(Request $request): JsonResponse
    {
        $bookings = Booking::with(['trip.route', 'trip.vehicle', 'trip.driver.user', 'payment', 'ticket'])
            ->where('customer_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $bookings]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trip_id' => 'required|exists:trips,id',
            'seat_number' => 'required|integer|min:1',
        ]);

        $trip = Trip::findOrFail($validated['trip_id']);
        if ($trip->status !== 'scheduled') {
            return response()->json(['message' => 'Trip is not available for booking'], 422);
        }

        $vehicle = $trip->vehicle;
        if ($validated['seat_number'] > $vehicle->total_seats) {
            return response()->json(['message' => 'Invalid seat number'], 422);
        }

        $exists = Booking::where('trip_id', $trip->id)
            ->where('seat_number', $validated['seat_number'])
            ->whereIn('status', ['pending', 'paid', 'completed'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Seat already booked'], 422);
        }

        if ($trip->available_seats < 1) {
            return response()->json(['message' => 'No seats available'], 422);
        }

        $booking = Booking::create([
            'trip_id' => $validated['trip_id'],
            'customer_id' => $request->user()->id,
            'seat_number' => $validated['seat_number'],
            'booking_reference' => strtoupper(Str::random(8)),
            'status' => 'pending',
        ]);

        $trip->decrement('available_seats');

        return response()->json(['data' => $booking->load('trip.route')], 201);
    }

    public function cancel(Booking $booking): JsonResponse
    {
        if ($booking->customer_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!in_array($booking->status, ['pending', 'paid'])) {
            return response()->json(['message' => 'Cannot cancel this booking'], 422);
        }

        $booking->update(['status' => 'cancelled']);
        $booking->trip->increment('available_seats');

        return response()->json(['message' => 'Booking cancelled', 'data' => $booking]);
    }
}
