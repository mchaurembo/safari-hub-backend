<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesTransportFleet;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\JobApplication;
use App\Services\EmploymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    use ResolvesTransportFleet;

    public function __construct(private EmploymentService $employment)
    {
    }

    /** List active (non-deleted) documents for the authenticated job seeker / driver */
    public function index(Request $request)
    {
        $driver = $request->user()->driver;
        if (!$driver) return response()->json(['data' => []]);

        // SoftDeletes scope automatically excludes deleted records
        $docs = DriverDocument::where('driver_id', $driver->id)->latest()->get();
        return response()->json(['data' => $docs]);
    }

    /** Upload a new document (creates seeker profile if needed — does not grant driver capability) */
    public function store(Request $request)
    {
        $request->validate([
            'document_type' => 'required|in:driving_license,psv_license,national_id,passport,medical_certificate,police_clearance,other',
            'label'         => 'nullable|string|max:100',
            'file'          => 'required|file|image|max:5120',
            'expiry_date'   => 'nullable|date',
        ]);

        $driver = $this->employment->ensureJobSeekerProfile($request->user());

        $file = $request->file('file');
        $path = $file->store("driver-docs/{$driver->id}", 'public');

        $doc = DriverDocument::create([
            'driver_id'     => $driver->id,
            'document_type' => $request->document_type,
            'label'         => $request->label,
            'file_path'     => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType(),
            'expiry_date'   => $request->expiry_date,
            'verified'      => 'pending',
        ]);

        return response()->json($doc, 201);
    }

    /** Stream document file (for thumbnails/preview; auth required) */
    public function file(Request $request, $id)
    {
        $driver = $request->user()->driver;
        if (!$driver) {
            abort(404);
        }

        $document = DriverDocument::where('id', $id)
            ->where('driver_id', $driver->id)
            ->firstOrFail();

        $path = $document->file_path;
        if (!$path || !Storage::disk('public')->exists($path)) {
            abort(404);
        }

        $mime = $document->mime_type ?? Storage::disk('public')->mimeType($path) ?? 'application/octet-stream';
        return response()->file(Storage::disk('public')->path($path), [
            'Content-Type' => $mime,
        ]);
    }

    /** Stream document file for owner viewing fleet-driver or applicant documents (auth required) */
    public function ownerFile(Request $request, $id)
    {
        $owner = $this->transportFleet($request);
        if (!$owner) {
            abort(404);
        }

        $document = DriverDocument::withTrashed()->findOrFail($id);

        $isFleetDriver = Driver::where('id', $document->driver_id)
            ->where('owner_id', $owner->id)
            ->exists();

        $hasApplicationAccess = JobApplication::where('driver_id', $document->driver_id)
            ->whereHas('posting', fn ($q) => $q->where('transport_owner_id', $owner->id))
            ->exists();

        if (! $isFleetDriver && ! $hasApplicationAccess) {
            abort(404);
        }

        $path = $document->file_path;
        if (!$path || !Storage::disk('public')->exists($path)) {
            abort(404);
        }

        $mime = $document->mime_type ?? Storage::disk('public')->mimeType($path) ?? 'application/octet-stream';
        return response()->file(Storage::disk('public')->path($path), [
            'Content-Type' => $mime,
        ]);
    }

    /** Soft-delete a document (record stays in DB, file stays on disk) */
    public function destroy(Request $request, $id)
    {
        $driver = $request->user()->driver;
        if (!$driver) return response()->json(['message' => 'Forbidden'], 403);

        // Use withTrashed so we can still find already-soft-deleted records
        $document = DriverDocument::where('id', $id)
            ->where('driver_id', $driver->id)
            ->firstOrFail();

        // Soft-delete only — do NOT delete the physical file
        $document->delete();

        return response()->json(['message' => 'Removed from your list']);
    }
}
