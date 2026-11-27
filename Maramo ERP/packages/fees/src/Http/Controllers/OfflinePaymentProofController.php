<?php

namespace Classivo\Fees\Http\Controllers;

use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Fees\Models\OfflinePayment;
use Classivo\Fees\Support\FeesPermissions;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET {prefix}/offline-payments/{id}/proof — streams an offline-payment proof
 * from the private attachments disk. The record is looked up with an explicit
 * school filter (route bindings run before tenant context), and only staff
 * holding `fees.collect` may open it. Always sent as a download so the browser
 * never renders it on the app origin.
 */
class OfflinePaymentProofController extends Controller
{
    public function __invoke(int $offlinePayment): Response
    {
        $record = OfflinePayment::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('school_id', current_school_id())
            ->findOrFail($offlinePayment);

        abort_unless(auth()->user()?->can(FeesPermissions::COLLECT), 403);

        $disk = Storage::disk((string) config('fees.attachments.disk', 'local'));
        $path = (string) $record->attachment_path;

        abort_if($path === '' || ! $disk->exists($path), 404);

        return $disk->download($path, 'proof-'.$record->id.'.'.pathinfo($path, PATHINFO_EXTENSION), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
