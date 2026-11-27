<?php

namespace Classivo\Hr\Http\Controllers;

use Classivo\Hr\Models\StaffDocument;
use Classivo\Hr\Support\Filename;
use Classivo\Hr\Support\HrPermissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorised download of a staff document from the private disk. Allowed for the
 * `hr.staff.view` ability or the staff member the document belongs to. Only
 * pdf/jpg/png are ever served (nosniff, attachment), whatever is on disk.
 */
class StaffDocumentController extends Controller
{
    private const TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public function download(Request $request, int $document): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        // Resolved by hand with an explicit school filter (see PayrollPdfController).
        $doc = StaffDocument::query()->where('school_id', current_school_id())->with('staff')->findOrFail($document);

        $own = $doc->staff?->user_id !== null && (int) $doc->staff->user_id === (int) $user->getAuthIdentifier();
        abort_unless($own || HrPermissions::can(HrPermissions::STAFF_VIEW), 403);

        $path = (string) $doc->file_path;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $disk = Storage::disk(config('hr.documents_disk', 'local'));

        abort_unless($path !== '' && isset(self::TYPES[$ext]) && $disk->exists($path), 404);

        return $disk->download($path, Filename::safe($doc->title).'.'.$ext, [
            'Content-Type' => self::TYPES[$ext],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
