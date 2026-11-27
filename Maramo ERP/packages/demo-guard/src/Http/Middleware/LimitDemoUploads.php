<?php

namespace Classivo\DemoGuard\Http\Middleware;

use Classivo\DemoGuard\DemoGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class LimitDemoUploads
{
    public function handle(Request $request, Closure $next)
    {
        if (DemoGuard::active() && $request->allFiles()) {
            $files = collect($request->allFiles())->flatten();
            $maxBytes = (int) config('demo-guard.uploads.max_kb', 2048) * 1024;
            $tooMany = $files->count() > (int) config('demo-guard.uploads.max_files', 3);
            $tooBig = $files->contains(fn ($f) => $f instanceof UploadedFile && ($f->getSize() ?: 0) > $maxBytes);
            if ($tooMany || $tooBig) {
                abort(413, __('demo-guard::messages.upload_limit', ['kb' => config('demo-guard.uploads.max_kb', 2048)]));
            }
        }

        return $next($request);
    }
}
