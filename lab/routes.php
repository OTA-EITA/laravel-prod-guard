
// --- laravel-prod-guard lab routes (appended to routes/web.php on the runner) ---
use Illuminate\Support\Facades\DB;

// A request that fails after running one query with a bound value, the way a
// real controller can fail halfway through. CSRF is skipped for lab/* in
// bootstrap/app.php so the request reaches the route.
Route::post('/lab/fail', function () {
    DB::select('select ? as probe', ['bound-value-7d1c']);
    throw new RuntimeException('lab failure');
});

// Under api/*, so the default CORS paths apply.
Route::get('/api/lab', fn () => ['ok' => true]);

// What the app believes the client address and scheme are.
Route::get('/lab/client', fn (Illuminate\Http\Request $r) => ['ip' => $r->ip(), 'secure' => $r->isSecure(), 'host' => $r->getHost()]);
