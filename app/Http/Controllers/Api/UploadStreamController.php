<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * NFR-SEC-004 (Session 38): the sole route that ever serves 'uploads'-disk
 * file bytes. Reached only via a signed URL minted by
 * Storage::disk('uploads')->temporaryUrl() (registered in
 * AppServiceProvider::boot() — the local disk driver has no built-in
 * temporary-URL support, unlike S3), which this route's 'signed' middleware
 * verifies before the controller ever runs. The 'path' query parameter is
 * itself covered by that signature, so a request that alters it fails
 * verification before reaching here — there is no separate path-traversal
 * check needed on top of that.
 */
class UploadStreamController extends Controller
{
    public function show(Request $request): StreamedResponse
    {
        $path = $request->string('path')->toString();

        abort_unless(Storage::disk('uploads')->exists($path), 404);

        return Storage::disk('uploads')->response($path);
    }
}
