<?php

namespace App\Services;

use App\Models\CargoRequest;
use App\Models\Driver;
use App\Models\EmploymentRelationship;
use App\Models\Garage;
use App\Models\GarageMember;
use App\Models\Role;
use App\Models\Technician;
use App\Models\TransportOwner;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EmploymentService
{
    public function __construct(private AuditLogger $audit) {}

    public function ensureGarageMembership(
        Garage $garage,
        User $user,
        string $membershipType,
        string $status = 'active'
    ): GarageMember {
        $member = GarageMember::firstOrNew([
            'garage_id' => $garage->id,
            'user_id' => $user->id,
            'membership_type' => $membershipType,
        ]);

        $member->status = $status;
        $member->left_at = $status === 'active' ? null : ($member->left_at ?? now());
        if (! $member->exists || ! $member->joined_at) {
            $member->joined_at = now();
        }
        $member->save();

        return $member;
    }

    public function endGarageMembership(Garage $garage, User $user, string $membershipType): void
    {
        GarageMember::where('garage_id', $garage->id)
            ->where('user_id', $user->id)
            ->where('membership_type', $membershipType)
            ->where('status', 'active')
            ->update([
                'status' => 'inactive',
                'left_at' => now(),
            ]);
    }

    /**
     * Hire (or transfer) a driver into a fleet. Creates/updates drivers row + employment history.
     */
    public function employDriver(
        TransportOwner $fleet,
        User $user,
        array $attrs = []
    ): Driver {
        if (! $user->hasCapability('driver')) {
            $user->enrollCapability('driver');
        }

        return DB::transaction(function () use ($fleet, $user, $attrs) {
            $existing = Driver::where('user_id', $user->id)->first();

            if ($existing
                && $existing->owner_id
                && (int) $existing->owner_id !== (int) $fleet->id
                && $existing->status === 'active'
            ) {
                throw new InvalidArgumentException('Driver is already employed by another fleet.');
            }

            if ($existing && $existing->owner_id && (int) $existing->owner_id !== (int) $fleet->id) {
                $this->endTransportEmployment($existing->owner_id, $user->id);
            }

            $driver = Driver::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'owner_id' => $fleet->id,
                    'license_number' => $attrs['license_number'] ?? $existing?->license_number ?? 'TEMP-DL',
                    'experience_years' => $attrs['experience_years'] ?? $existing?->experience_years ?? 0,
                    'status' => 'active',
                ]
            );

            $this->upsertEmployment(
                EmploymentRelationship::EMPLOYER_TRANSPORT,
                $fleet->id,
                $user->id,
                EmploymentRelationship::TYPE_DRIVER,
                'driver'
            );

            $this->audit->log('driver.employed', $driver, null, [
                'fleet_id' => $fleet->id,
                'user_id' => $user->id,
            ]);

            return $driver->load('user');
        });
    }

    /**
     * Unassign a driver from fleet vehicles, end employment, and revoke driver capability.
     * Does not delete trips, cargo, or payment history.
     */
    public function releaseDriverFromFleet(Driver $driver): void
    {
        DB::transaction(function () use ($driver) {
            $fleetId = $driver->owner_id;
            $user = $driver->user;

            $driver->vehicles()->detach();
            $driver->update([
                'status' => 'inactive',
                'owner_id' => null,
            ]);

            if ($fleetId && $user) {
                $this->endTransportEmployment((int) $fleetId, $user->id);
            }

            if (! $user) {
                return;
            }

            $stillEmployed = Driver::query()
                ->where('user_id', $user->id)
                ->whereNotNull('owner_id')
                ->where('status', 'active')
                ->exists();

            if ($stillEmployed) {
                return;
            }

            $role = Role::where('name', 'driver')->first();
            if ($role && $user->roles()->where('roles.id', $role->id)->exists()) {
                $user->roles()->updateExistingPivot($role->id, [
                    'status' => 'revoked',
                    'ended_at' => now(),
                ]);
                $user->unsetRelation('roles');
                $user->refreshLegacyPrimaryRole();
            }

            $this->audit->log('driver.released', $driver, null, [
                'fleet_id' => $fleetId,
                'user_id' => $user->id,
            ]);
        });
    }

    /** Release every driver employed by this fleet (used when the owner leaves the service). */
    public function releaseFleetDrivers(TransportOwner $fleet): int
    {
        $drivers = Driver::query()->where('owner_id', $fleet->id)->get();
        foreach ($drivers as $driver) {
            $this->releaseDriverFromFleet($driver);
        }

        return $drivers->count();
    }

    public function fleetHasActiveWork(TransportOwner $fleet): bool
    {
        $driverIds = Driver::where('owner_id', $fleet->id)->pluck('id');
        $vehicleIds = Vehicle::where('owner_id', $fleet->id)->pluck('id');

        if ($driverIds->isEmpty() && $vehicleIds->isEmpty()) {
            return false;
        }

        $linked = function ($q) use ($driverIds, $vehicleIds) {
            if ($driverIds->isNotEmpty()) {
                $q->orWhereIn('driver_id', $driverIds);
            }
            if ($vehicleIds->isNotEmpty()) {
                $q->orWhereIn('vehicle_id', $vehicleIds);
            }
        };

        if (CargoRequest::query()
            ->whereIn('status', ['accepted', 'in_progress', 'delivered'])
            ->where($linked)
            ->exists()) {
            return true;
        }

        return Trip::query()
            ->whereIn('status', ['scheduled', 'boarding', 'in_progress'])
            ->where($linked)
            ->exists();
    }

    /**
     * Ensure a job-seeker driver profile exists (no fleet, no driver capability).
     * Driver capability is granted later when a transport owner hires the person.
     */
    public function ensureJobSeekerProfile(User $user, array $attrs = []): Driver
    {
        $existing = Driver::where('user_id', $user->id)->first();
        if ($existing) {
            if (! empty($attrs['license_number']) || array_key_exists('experience_years', $attrs)) {
                $existing->update(array_filter([
                    'license_number' => $attrs['license_number'] ?? null,
                    'experience_years' => $attrs['experience_years'] ?? null,
                ], fn ($v) => $v !== null));
            }

            return $existing->fresh();
        }

        return Driver::create([
            'user_id' => $user->id,
            'owner_id' => null,
            'license_number' => $attrs['license_number'] ?? 'TEMP-DL',
            'experience_years' => $attrs['experience_years'] ?? 0,
            'status' => 'active',
        ]);
    }

    /** @deprecated Use ensureJobSeekerProfile — does not grant driver capability. */
    public function ensureIndependentDriverProfile(User $user, array $attrs = []): Driver
    {
        return $this->ensureJobSeekerProfile($user, $attrs);
    }

    public function employTechnician(Garage $garage, User $user, ?string $specialization = null): Technician
    {
        $user->enrollCapability('technician');

        return DB::transaction(function () use ($garage, $user, $specialization) {
            $tech = Technician::firstOrCreate(
                ['user_id' => $user->id, 'garage_id' => $garage->id],
                [
                    'specialization' => $specialization ?? 'General',
                    'status' => 'active',
                ]
            );

            if ($specialization) {
                $tech->update(['specialization' => $specialization, 'status' => 'active']);
            }

            $this->ensureGarageMembership($garage, $user, GarageMember::TYPE_TECHNICIAN);
            $this->upsertEmployment(
                EmploymentRelationship::EMPLOYER_GARAGE,
                $garage->id,
                $user->id,
                EmploymentRelationship::TYPE_TECHNICIAN,
                'technician'
            );

            $this->audit->log('technician.employed', $tech, null, [
                'garage_id' => $garage->id,
                'user_id' => $user->id,
            ]);

            return $tech->load('user', 'garage');
        });
    }

    public function ensureGarageOwnerMembership(Garage $garage): GarageMember
    {
        $owner = User::findOrFail($garage->owner_id);
        $owner->enrollCapability('garage_owner');

        return $this->ensureGarageMembership($garage, $owner, GarageMember::TYPE_OWNER);
    }

    private function upsertEmployment(
        string $employerType,
        int $employerId,
        int $employeeUserId,
        string $employmentType,
        ?string $position = null
    ): EmploymentRelationship {
        $active = EmploymentRelationship::query()
            ->where('employer_type', $employerType)
            ->where('employer_id', $employerId)
            ->where('employee_user_id', $employeeUserId)
            ->where('employment_type', $employmentType)
            ->where('status', 'active')
            ->first();

        if ($active) {
            return $active;
        }

        return EmploymentRelationship::create([
            'employer_type' => $employerType,
            'employer_id' => $employerId,
            'employee_user_id' => $employeeUserId,
            'employment_type' => $employmentType,
            'position' => $position,
            'start_date' => now()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function endTransportEmployment(int $fleetId, int $userId): void
    {
        EmploymentRelationship::query()
            ->where('employer_type', EmploymentRelationship::EMPLOYER_TRANSPORT)
            ->where('employer_id', $fleetId)
            ->where('employee_user_id', $userId)
            ->where('employment_type', EmploymentRelationship::TYPE_DRIVER)
            ->where('status', 'active')
            ->update([
                'status' => 'ended',
                'end_date' => now()->toDateString(),
            ]);
    }
}
