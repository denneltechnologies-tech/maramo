<?php

use App\Http\Controllers\PublicApiController;
use Classivo\Cms\CmsPlugin;
use Illuminate\Support\Facades\Route;

// Public Website & CRM Integration Routes
Route::prefix('api')->group(function () {
    Route::get('/courses', [PublicApiController::class, 'courses']);
    Route::post('/applications', [PublicApiController::class, 'apply']);
    Route::post('/inquiries', [PublicApiController::class, 'inquiry']);
    Route::get('/verify-certificate/{code}', [PublicApiController::class, 'verifyCertificate']);
});

// Serve Public Website Homepage and Clean URL Routes
Route::get('/', function () {
    $path = public_path('index.html');
    if (file_exists($path)) {
        return response()->file($path);
    }
    return redirect('/admin');
});

foreach (['about', 'services', 'programs', 'gallery', 'contact'] as $page) {
    Route::get('/' . $page, function () use ($page) {
        $path = public_path($page . '.html');
        if (file_exists($path)) {
            return response()->file($path);
        }
        return abort(404);
    });
}

// Generic sign-in entry point (used by package routes behind the `auth`
// middleware, e.g. PDF downloads and the LMS storefront). Each portal has
// its own sign-in page; staff go to the admin panel by default.
Route::get('/login', function () {
    $intended = (string) session('url.intended', '');
    $path = parse_url($intended, PHP_URL_PATH) ?: '';

    foreach (['student', 'parent'] as $portal) {
        if (str_starts_with($path, '/'.$portal)) {
            return redirect('/'.$portal.'/login');
        }
    }

    return redirect('/admin/login');
})->name('login');
