<?php

use App\Helpers\PhoneHelper;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normalize existing phone values (+255, 255, 0, 9-digit) to 0XXXXXXXXX format
     * so the unique constraint on phone correctly prevents duplicates across formats.
     */
    public function up(): void
    {
        $users = User::whereNotNull('phone')->where('phone', '!=', '')->get();
        foreach ($users as $user) {
            $normalized = PhoneHelper::normalize($user->phone);
            if ($normalized !== $user->phone) {
                DB::table('users')->where('id', $user->id)->update(['phone' => $normalized]);
            }
        }

        // Normalize whatsapp_number for consistency
        $users = User::whereNotNull('whatsapp_number')->where('whatsapp_number', '!=', '')->get();
        foreach ($users as $user) {
            $normalized = PhoneHelper::normalize($user->whatsapp_number);
            if ($normalized !== $user->whatsapp_number) {
                DB::table('users')->where('id', $user->id)->update(['whatsapp_number' => $normalized]);
            }
        }
    }

    public function down(): void
    {
        // Cannot reliably reverse normalization
    }
};
