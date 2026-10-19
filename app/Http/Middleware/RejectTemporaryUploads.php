<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectTemporaryUploads
{
    /**
     * Disable Livewire's temporary upload and preview endpoints.
     *
     * Livewire registers them with the "web" middleware only, outside the tenant
     * context: an upload would land in central storage whatever the host, and a signed
     * upload URL is not bound to a tenant. No feature uses file uploads yet, so the
     * endpoints are refused until tenant-aware upload handling is designed.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort(404);
    }
}
