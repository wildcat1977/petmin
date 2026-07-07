<?php

use App\Http\Controllers\Api\MapPointsController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\GmailAuthController;
use App\Http\Middleware\SetLocale;
use App\Models\Report;
use App\Models\Shelter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, SetLocale::SUPPORTED, true)) {
        session(['locale' => $locale]);
    }

    return redirect()->back(302, [], route('home'));
})->name('locale.set');

Route::view('/', 'pages.radar')->name('home');

Route::view('/reports', 'pages.reports')->name('reports');

// 點位 API（server-side bbox query + geohash 聚合，spec §7.2）
Route::get('/api/map/reports', MapPointsController::class)->name('api.map.reports');

// 收容所點位（開設中的優先呈現；資料由 data.taipei 同步 + 後台維護）
Route::get('/api/map/shelters', function () {
    return response()->json(Cache::remember('map.shelters', 60, fn () => Shelter::query()
        ->mappable()
        ->orderByDesc('is_open')
        ->get(['id', 'name', 'latitude', 'longitude', 'address', 'capacity', 'is_open'])
        ->map(fn (Shelter $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'lat' => (float) $s->latitude,
            'lng' => (float) $s->longitude,
            'address' => $s->address,
            'capacity' => $s->capacity,
            'open' => $s->is_open,
        ])->all()));
})->name('api.map.shelters');

Route::get('/reports/export', function () {
    $reports = Report::query()
        ->with('user:id,name')
        ->whereNotIn('status', [Report::STATUS_REJECTED])
        ->latest()
        ->get();

    $statusLabels = [
        Report::STATUS_PENDING => '待審核',
        Report::STATUS_APPROVED => '已審核',
        Report::STATUS_REJECTED => '已退件',
    ];

    $filename = '災情回報清單_'.now()->format('Ymd_His').'.csv';

    $headers = [
        'Content-Type' => 'text/csv; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="'.$filename.'"',
    ];

    $callback = function () use ($reports, $statusLabels) {
        $handle = fopen('php://output', 'w');
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($handle, ['回報時間', '分類', '補充數值', '地點', '審核狀態', '核實層級', '已排除', '說明', '照片網址', '緯度', '經度']);

        foreach ($reports as $report) {
            fputcsv($handle, [
                $report->created_at->format('Y-m-d H:i'),
                Report::CATEGORY_LABELS[$report->category] ?? $report->category,
                $report->extra_value !== null ? (string) $report->extra_value : '',
                $report->address ?: '',
                $statusLabels[$report->status] ?? $report->status,
                Report::VERIFICATION_LABELS[$report->verification_level] ?? $report->verification_level,
                $report->resolved_at?->format('Y-m-d H:i') ?? '',
                $report->description ?? '',
                $report->publicImageUrl() ?? '',
                (string) $report->latitude,
                (string) $report->longitude,
            ]);
        }

        fclose($handle);
    };

    return response()->stream($callback, 200, $headers);
})->name('reports.export');

Route::middleware('auth')->group(function () {
    Route::view('/profile', 'pages.profile')->name('profile');

    // Gmail OAuth2 授權（管理員用）
    Route::get('/admin/gmail/authorize', [GmailAuthController::class, 'authorize'])
        ->name('admin.gmail.authorize');
    Route::get('/admin/gmail/callback', [GmailAuthController::class, 'callback'])
        ->name('admin.gmail.callback');
});

Route::get('/transparency', function () {
    $stats = Cache::remember('transparency.stats', 3600, function () {
        $rejectionBreakdown = DB::table('reports')
            ->where('status', 'rejected')
            ->whereNotNull('rejection_reason')
            ->selectRaw('rejection_reason, COUNT(*) as c')
            ->groupBy('rejection_reason')
            ->orderByDesc('c')
            ->pluck('c', 'rejection_reason')
            ->all(); // plain array to avoid Collection serialization issues

        // 週統計：每週 ISO 起始日 + 通報總數（created_at 已是台北時間，直接使用）
        $weekly = DB::table('reports')
            ->selectRaw("TO_CHAR(DATE_TRUNC('week', created_at), 'YYYY-MM-DD') AS label, COUNT(*) AS total")
            ->groupByRaw("DATE_TRUNC('week', created_at)")
            ->orderByRaw("DATE_TRUNC('week', created_at)")
            ->get()
            ->map(fn ($r) => ['label' => $r->label, 'total' => (int) $r->total])
            ->values()
            ->all();

        // 日統計：近 90 天每日通報數
        $daily = DB::table('reports')
            ->selectRaw("TO_CHAR(created_at::date, 'YYYY-MM-DD') AS label, COUNT(*) AS total")
            ->where('created_at', '>=', now()->subDays(90))
            ->groupByRaw('created_at::date')
            ->orderByRaw('created_at::date')
            ->get()
            ->map(fn ($r) => ['label' => $r->label, 'total' => (int) $r->total])
            ->values()
            ->all();

        // 地圖散點資料：epoch 需告知 PG「此值是台北時間」才能取到正確 UTC epoch
        $mapPoints = DB::table('reports')
            ->whereIn('status', Report::VISIBLE_STATUSES)
            ->selectRaw("latitude::float AS lat, longitude::float AS lng, category, EXTRACT(EPOCH FROM (created_at AT TIME ZONE 'Asia/Taipei'))::int AS ts")
            ->orderBy('created_at')
            ->get()
            ->map(fn ($r) => [(float) $r->lat, (float) $r->lng, $r->category, (int) $r->ts])
            ->values()
            ->all();

        return [
            'total' => Report::count(),
            'approved' => Report::where('status', Report::STATUS_APPROVED)->count(),
            'pending' => Report::where('status', Report::STATUS_PENDING)->count(),
            'rejected' => Report::where('status', Report::STATUS_REJECTED)->count(),
            'ai_auto' => (int) ($rejectionBreakdown['ai_auto'] ?? 0),
            'avg_review_hrs' => (float) (DB::table('reports')
                ->whereNotNull('first_reviewed_at')
                ->selectRaw('AVG(EXTRACT(EPOCH FROM (first_reviewed_at - created_at))/3600) AS avg_hrs')
                ->value('avg_hrs') ?? 0),
            'rejection_breakdown' => $rejectionBreakdown,
            'weekly' => $weekly,
            'daily' => $daily,
            'map_points' => $mapPoints,
        ];
    });

    return view('pages.transparency', compact('stats'));
})->name('transparency');

Route::get('/stats', function () {
    // 共用 transparency 的快取 key，不重複定義 closure
    $stats = Cache::remember('transparency.stats', 3600, function () {
        $rejectionBreakdown = DB::table('reports')
            ->where('status', 'rejected')
            ->whereNotNull('rejection_reason')
            ->selectRaw('rejection_reason, COUNT(*) as c')
            ->groupBy('rejection_reason')
            ->orderByDesc('c')
            ->pluck('c', 'rejection_reason')
            ->all();

        // created_at 已是台北本地時間，直接使用，無需轉換時區
        $weekly = DB::table('reports')
            ->selectRaw("TO_CHAR(DATE_TRUNC('week', created_at), 'YYYY-MM-DD') AS label, COUNT(*) AS total")
            ->groupByRaw("DATE_TRUNC('week', created_at)")
            ->orderByRaw("DATE_TRUNC('week', created_at)")
            ->get()
            ->map(fn ($r) => ['label' => $r->label, 'total' => (int) $r->total])
            ->values()
            ->all();

        $daily = DB::table('reports')
            ->selectRaw("TO_CHAR(created_at::date, 'YYYY-MM-DD') AS label, COUNT(*) AS total")
            ->where('created_at', '>=', now()->subDays(90))
            ->groupByRaw('created_at::date')
            ->orderByRaw('created_at::date')
            ->get()
            ->map(fn ($r) => ['label' => $r->label, 'total' => (int) $r->total])
            ->values()
            ->all();

        // epoch 需告知 PG「此值是台北時間」才能取到正確 UTC epoch
        $mapPoints = DB::table('reports')
            ->whereIn('status', Report::VISIBLE_STATUSES)
            ->selectRaw("latitude::float AS lat, longitude::float AS lng, category, EXTRACT(EPOCH FROM (created_at AT TIME ZONE 'Asia/Taipei'))::int AS ts")
            ->orderBy('created_at')
            ->get()
            ->map(fn ($r) => [(float) $r->lat, (float) $r->lng, $r->category, (int) $r->ts])
            ->values()
            ->all();

        return [
            'total' => Report::count(),
            'approved' => Report::where('status', Report::STATUS_APPROVED)->count(),
            'pending' => Report::where('status', Report::STATUS_PENDING)->count(),
            'rejected' => Report::where('status', Report::STATUS_REJECTED)->count(),
            'ai_auto' => (int) ($rejectionBreakdown['ai_auto'] ?? 0),
            'avg_review_hrs' => (float) (DB::table('reports')
                ->whereNotNull('first_reviewed_at')
                ->selectRaw('AVG(EXTRACT(EPOCH FROM (first_reviewed_at - created_at))/3600) AS avg_hrs')
                ->value('avg_hrs') ?? 0),
            'rejection_breakdown' => $rejectionBreakdown,
            'weekly' => $weekly,
            'daily' => $daily,
            'map_points' => $mapPoints,
        ];
    });

    return view('pages.stats', compact('stats'));
})->name('stats');

Route::get('/contact', fn () => view('pages.contact'))->name('contact');

// EOC dashboard（公開唯讀大屏，spec §7.3）
Route::view('/dashboard', 'pages.dashboard')->name('dashboard');

// 雷達回波 proxy（scheduled fetch 落地的圖檔；前端 ImageOverlay 讀本站，spec §5）
Route::get('/api/radar-echo', function () {
    $path = Storage::disk('public')->path('radar/latest.png');
    abort_unless(file_exists($path), 404);

    return response()->file($path, [
        'Content-Type' => 'image/png',
        'Cache-Control' => 'public, max-age=60',
    ]);
})->name('api.radar-echo');

Route::post('/logout', function () {
    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('home');
})->name('logout');

Route::middleware('guest')->group(function () {
    Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirect'])
        ->whereIn('provider', ['google', 'line'])
        ->name('auth.redirect');

    Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])
        ->whereIn('provider', ['google', 'line'])
        ->name('auth.callback');

    // LIFF 內建瀏覽器專用 callback（不需 Socialite OAuth，直接用 access token）
    Route::get('/auth/line/liff-callback', [SocialiteController::class, 'liffCallback'])
        ->name('auth.line.liff');

    // 後台專用 Google OAuth 入口（共用同一個 callback URL，以 session flag 區分）
    Route::get('/admin/auth/google', [SocialiteController::class, 'adminRedirect'])
        ->name('admin.auth.google.redirect');
});
