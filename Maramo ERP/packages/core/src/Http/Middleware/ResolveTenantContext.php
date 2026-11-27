<?php

namespace Classivo\Core\Http\Middleware;

use Classivo\Core\Tenancy\CurrentContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hydrates {@see CurrentContext} for a web request: the school comes from the
 * bound TenantResolver; branch and academic session come from the Laravel
 * session (`classivo.branch_id` / `classivo.session_id`), defaulting to the
 * school's default branch and current session. Then activates the scopes.
 */
class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(CurrentContext::class);
        $session = $request->hasSession() ? $request->session() : null;

        $branchId = $session?->get('classivo.branch_id');

        if ($branchId !== null) {
            $context->setBranchId((int) $branchId);
        } elseif (($resolved = $context->branchId()) !== null) {
            $session?->put('classivo.branch_id', $resolved);
        }

        $sessionId = $session?->get('classivo.session_id');

        if ($sessionId !== null) {
            $context->setSessionId((int) $sessionId);
        } elseif (($resolved = $context->sessionId()) !== null) {
            $session?->put('classivo.session_id', $resolved);
        }

        $context->markResolved();

        return $next($request);
    }
}
