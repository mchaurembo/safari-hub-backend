<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobPosting;
use App\Models\JobApplication;
use App\Services\EmploymentService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class JobController extends Controller
{
    public function __construct(private EmploymentService $employment) {}
    /* ─────────────────────────────────────────────
       OWNER  endpoints
    ───────────────────────────────────────────── */

    /** List all job postings for the authenticated owner */
    public function ownerPostings(Request $request)
    {
        $owner = $request->user()->transportOwner;
        if (!$owner) return response()->json(['message' => 'Owner profile not found'], 404);

        $postings = JobPosting::with(['applications.driver.user'])
            ->where('transport_owner_id', $owner->id)
            ->latest()
            ->get();

        return response()->json($postings);
    }

    /** Create a new job posting */
    public function createPosting(Request $request)
    {
        $owner = $request->user()->transportOwner;
        if (!$owner) return response()->json(['message' => 'Owner profile not found'], 404);
        if ($owner->status !== 'approved') return response()->json(['message' => 'Account not approved'], 403);

        $data = $request->validate([
            'title'          => 'required|string|max:255',
            'description'    => 'required|string',
            'transport_type' => 'required|in:cargo,passenger,both',
            'location'       => 'nullable|string|max:255',
            'requirements'   => 'nullable|string',
            'salary_min'     => 'nullable|numeric|min:0',
            'salary_max'     => 'nullable|numeric|min:0',
        ]);

        $posting = JobPosting::create(array_merge($data, [
            'transport_owner_id' => $owner->id,
            'status' => 'open',
        ]));

        return response()->json($posting->load('applications'), 201);
    }

    /** Update a posting (title, description, status, etc.) */
    public function updatePosting(Request $request, JobPosting $posting)
    {
        $owner = $request->user()->transportOwner;
        if (!$owner || $posting->transport_owner_id !== $owner->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'title'          => 'sometimes|string|max:255',
            'description'    => 'sometimes|string',
            'transport_type' => 'sometimes|in:cargo,passenger,both',
            'location'       => 'nullable|string|max:255',
            'requirements'   => 'nullable|string',
            'salary_min'     => 'nullable|numeric|min:0',
            'salary_max'     => 'nullable|numeric|min:0',
            'status'         => 'sometimes|in:open,closed,filled',
        ]);

        $posting->update($data);
        return response()->json($posting->load('applications.driver.user'));
    }

    /** Delete a posting */
    public function deletePosting(Request $request, JobPosting $posting)
    {
        $owner = $request->user()->transportOwner;
        if (!$owner || $posting->transport_owner_id !== $owner->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $posting->delete();
        return response()->json(['message' => 'Deleted']);
    }

    /** List applications for a specific posting */
    public function postingApplications(Request $request, JobPosting $posting)
    {
        $owner = $request->user()->transportOwner;
        if (!$owner || $posting->transport_owner_id !== $owner->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $applications = $posting->applications()->with('driver.user', 'driver.documents')->latest()->get();

        // Resolve attached documents:
        // - If driver explicitly chose documents, show those.
        // - If none were attached (null / empty), show ALL of the driver's documents.
        $applications->each(function ($app) {
            $ids = $app->attached_document_ids ?? [];
            if (!empty($ids)) {
                // withTrashed: show even documents the driver later removed from their own list
                $app->attached_documents = \App\Models\DriverDocument::withTrashed()->whereIn('id', $ids)->get();
            } else {
                // Fall back to ALL documents (including soft-deleted) for this driver
                $app->attached_documents = \App\Models\DriverDocument::withTrashed()->where('driver_id', $app->driver_id)->get();
            }
        });

        return response()->json($applications);
    }

    /** Accept or reject an application */
    public function reviewApplication(Request $request, JobApplication $application)
    {
        $owner = $request->user()->transportOwner;
        if (!$owner || $application->posting->transport_owner_id !== $owner->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'status'     => 'required|in:accepted,rejected',
            'owner_note' => 'nullable|string',
            'mark_filled' => 'sometimes|boolean',
        ]);

        $application->loadMissing('driver.user', 'posting');

        if ($data['status'] === 'accepted') {
            $driverUser = $application->driver?->user;
            if (! $driverUser) {
                return response()->json(['message' => 'Applicant user missing'], 422);
            }

            try {
                $this->employment->employDriver($owner, $driverUser, [
                    'license_number' => $application->driver->license_number,
                    'experience_years' => $application->driver->experience_years,
                ]);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $application->update([
                'status' => 'accepted',
                'owner_note' => $data['owner_note'] ?? $application->owner_note,
            ]);

            if ($data['mark_filled'] ?? true) {
                $application->posting->update(['status' => 'filled']);
            }
        } else {
            $application->update($data);
        }

        return response()->json($application->fresh()->load('driver.user', 'posting'));
    }

    /* ─────────────────────────────────────────────
       DRIVER  endpoints
    ───────────────────────────────────────────── */

    /** Browse open job postings — any authenticated user (job seeker / customer). */
    public function browsePostings(Request $request)
    {
        $user = $request->user();
        $seeker = $user->driver;

        $postings = JobPosting::with(['owner.user'])
            ->where('status', 'open')
            ->latest()
            ->get()
            ->map(function ($p) use ($seeker) {
                $p->my_application = $seeker
                    ? $p->applications()->where('driver_id', $seeker->id)->first()
                    : null;
                $p->applications_count = $p->applications()->count();
                return $p;
            });

        return response()->json($postings);
    }

    /** Apply to a job posting as a job seeker (driver role granted only when owner accepts). */
    public function applyToPosting(Request $request, JobPosting $posting)
    {
        $user = $request->user();

        if ($posting->status !== 'open') {
            return response()->json(['message' => 'This job is no longer open'], 422);
        }

        // Already employed by another fleet — block applying elsewhere while active.
        if ($user->driver && $user->driver->owner_id && $user->driver->status === 'active') {
            return response()->json([
                'message' => 'You are already employed by a transport fleet. Leave that employment before applying elsewhere.',
            ], 422);
        }

        $data = $request->validate([
            'cover_note'            => 'nullable|string|max:1000',
            'license_number'        => 'nullable|string|max:50',
            'experience_years'      => 'nullable|integer|min:0',
            'attached_document_ids' => 'nullable|array',
            'attached_document_ids.*' => 'integer|exists:driver_documents,id',
        ]);

        $seeker = $this->employment->ensureJobSeekerProfile($user, [
            'license_number' => $data['license_number'] ?? null,
            'experience_years' => $data['experience_years'] ?? null,
        ]);

        $existing = JobApplication::where('job_posting_id', $posting->id)
            ->where('driver_id', $seeker->id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'You have already applied to this job'], 422);
        }

        $application = JobApplication::create([
            'job_posting_id'        => $posting->id,
            'driver_id'             => $seeker->id,
            'cover_note'            => $data['cover_note'] ?? null,
            'attached_document_ids' => $data['attached_document_ids'] ?? [],
            'status'                => 'pending',
        ]);

        return response()->json($application->load('posting.owner.user'), 201);
    }

    /** Job seeker / driver views their own applications */
    public function myApplications(Request $request)
    {
        $user = $request->user();
        $seeker = $user->driver;
        if (!$seeker) return response()->json([]);

        $applications = JobApplication::with(['posting.owner.user'])
            ->where('driver_id', $seeker->id)
            ->latest()
            ->get();

        $applications->each(function ($app) {
            $ids = $app->attached_document_ids ?? [];
            if (!empty($ids)) {
                // withTrashed: show even documents the driver later removed from their own list
                $app->attached_documents = \App\Models\DriverDocument::withTrashed()->whereIn('id', $ids)->get();
            } else {
                // Fall back to ALL documents (including soft-deleted) for this driver
                $app->attached_documents = \App\Models\DriverDocument::withTrashed()->where('driver_id', $app->driver_id)->get();
            }
        });

        return response()->json($applications);
    }

    /** Driver updates attached documents on a pending application */
    public function updateApplication(Request $request, JobApplication $application)
    {
        $driver = $request->user()->driver;
        if (!$driver || $application->driver_id !== $driver->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($application->status !== 'pending') {
            return response()->json(['message' => 'Cannot update a reviewed application'], 422);
        }

        $data = $request->validate([
            'attached_document_ids'   => 'nullable|array',
            'attached_document_ids.*' => 'integer',
            'cover_note'              => 'nullable|string|max:1000',
        ]);

        $application->update($data);

        // Return with resolved documents (withTrashed so owner still sees them)
        $ids = $application->attached_document_ids ?? [];
        $application->attached_documents = $ids
            ? \App\Models\DriverDocument::withTrashed()->whereIn('id', $ids)->get()
            : \App\Models\DriverDocument::withTrashed()->where('driver_id', $driver->id)->get();

        return response()->json($application);
    }

    /** Driver withdraws an application */
    public function withdrawApplication(Request $request, JobApplication $application)
    {
        $driver = $request->user()->driver;
        if (!$driver || $application->driver_id !== $driver->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($application->status !== 'pending') {
            return response()->json(['message' => 'Cannot withdraw a reviewed application'], 422);
        }
        $application->delete();
        return response()->json(['message' => 'Application withdrawn']);
    }
}
