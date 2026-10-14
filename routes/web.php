<?php

use App\Http\Controllers\Api\BinsController;
use App\Http\Controllers\Api\MapPointsController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\GmailAuthController;
use App\Http\Middleware\SetLocale;
use App\Models\Announcement;
use App\Models\DemandCell;
use App\Models\Report;
use App\Services\BagBoxGapService;
use App\Services\DemandScoreService;
use App\Support\DemandGrid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, SetLocale::SUPPORTED, true)) {
        session(['locale' => $locale]);
    }

    return redirect()->back(302, [], route('home'));
})->name('locale.set');

Route::view('/', 'pages.radar')->name('home');

Route::view('/reports', 'pages.reports')->name('reports');

Route::view('/leaderboard', 'pages.leaderboard')->name('leaderboard');

// 候選復設點 + 撤除風險 + 有袋沒桶（需求分數由 demand:recompute 落地到 demand_cells）
Route::get('/candidates', function (DemandScoreService $demand, BagBoxGapService $gaps) {
    return view('pages.candidates', [
        'candidates' => $demand->topCandidates(),
        'dumping' => $demand->dumpingRisk(),
        'gaps' => $gaps->gaps(),
        'computedAt' => DemandCell::max('computed_at'),
    ]);
})->name('candidates');

Route::get('/candidates/export', function (DemandScoreService $demand) {
    $rows = $demand->topCandidates(200);
    $headers = [
        'Content-Type' => 'text/csv; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="petmin_候選復設點_'.now()->format('Ymd_His').'.csv"',
    ];

    return response()->stream(function () use ($rows) {
        $h = fopen('php://output', 'w');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['排名', '網格', '緯度', '經度', '需求分數', '撤除風險分數', '證據明細']);
        foreach ($rows as $i => $cell) {
            $evidence = collect($cell->breakdown)->map(fn ($b, $k) => "{$k}×{$b['count']}".(! empty($b['samples']) ? '（'.implode('、', $b['samples']).'）' : ''))->implode('；');
            fputcsv($h, [$i + 1, $cell->cell, $cell->latitude, $cell->longitude, $cell->score, $cell->dumping_score, $evidence]);
        }
        fclose($h);
    }, 200, $headers);
})->name('candidates.export');

// 最新消息：上稿看 published_at；cache 60s 存純陣列
Route::get('/news', function () {
    $items = Cache::remember('petmin:news-page:'.app()->getLocale(), 60, fn () => Announcement::published()
        ->orderByDesc('is_pinned')
        ->orderByDesc('published_at')
        ->get()
        ->map(fn (Announcement $a) => [
            'title' => $a->title,
            'category' => $a->category,
            'body' => $a->body ? str($a->body)->sanitizeHtml()->toString() : null,
            'content' => $a->content,
            'link_url' => $a->link_url,
            'is_pinned' => $a->is_pinned,
            'published_at' => $a->published_at->format('Y-m-d H:i'),
        ])->all());

    return view('pages.news', ['items' => $items]);
})->name('news');

// 點位 API（server-side bbox query + geohash 聚合）
Route::get('/api/map/reports', MapPointsController::class)->name('api.map.reports');
Route::get('/api/map/bins', [BinsController::class, 'index'])->name('api.map.bins');
Route::get('/api/map/bins/nearby', [BinsController::class, 'nearby'])->name('api.map.bins.nearby');

// 需求色塊：只傳格子編號＋分數，前端用 grid 參數自己畫格（8,000 格也只有百來 KB）
Route::get('/api/map/demand', function () {
    return response()->json(Cache::remember('map.demand', 300, fn () => [
        'grid' => DemandGrid::fromConfig()->toArray(),
        'cells' => DemandCell::query()
            ->where('score', '>', 0)
            ->get(['ix', 'iy', 'score'])
            ->map(fn (DemandCell $c) => [$c->ix, $c->iy, (float) $c->score])
            ->all(),
    ]));
})->name('api.map.demand');

// 點色塊看明細：點擊位置所在格的分數組成（分數 0 的格子也回，說明被附近現存桶扣掉）
Route::get('/api/map/demand/at', function (Request $request) {
    $validated = $request->validate([
        'lat' => ['required', 'numeric', 'between:-90,90'],
        'lng' => ['required', 'numeric', 'between:-180,180'],
    ]);
    $grid = DemandGrid::fromConfig();
    [$ix, $iy] = $grid->cellAt((float) $validated['lat'], (float) $validated['lng']);
    $cell = DemandCell::where('cell', $grid->id($ix, $iy))->first();

    return response()->json(['cell' => $cell?->explanation()]);
})->name('api.map.demand.at');

// 有袋沒桶圖層
Route::get('/api/map/gaps', fn (BagBoxGapService $gaps) => response()->json($gaps->gaps()->all()))->name('api.map.gaps');

Route::get('/reports/export', function () {
    $reports = Report::query()
        ->with(['user:id,name', 'trashBin:id,address'])
        ->whereNotIn('status', [Report::STATUS_REJECTED])
        ->latest()
        ->get();

    $statusLabels = [
        Report::STATUS_PENDING => '待審核',
        Report::STATUS_APPROVED => '已審核',
        Report::STATUS_REJECTED => '已退件',
    ];

    $filename = '垃圾桶回報清單_'.now()->format('Ymd_His').'.csv';

    $headers = [
        'Content-Type' => 'text/csv; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="'.$filename.'"',
    ];

    $callback = function () use ($reports, $statusLabels) {
        $handle = fopen('php://output', 'w');
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($handle, ['回報時間', '分類', '對應桶位', '補充數值', '地點', '審核狀態', '核實層級', '已通報', '已處理', '說明', '照片網址', '緯度', '經度']);

        foreach ($reports as $report) {
            fputcsv($handle, [
                $report->created_at->format('Y-m-d H:i'),
                $report->categoryLabel(),
                $report->trashBin?->address ?? '',
                $report->extra_value !== null ? (string) $report->extra_value : '',
                $report->address ?: '',
                $statusLabels[$report->status] ?? $report->status,
                Report::VERIFICATION_LABELS[$report->verification_level] ?? $report->verification_level,
                $report->notified_at?->format('Y-m-d H:i') ?? '',
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

/*
 * 統計頁：PostgreSQL 專屬 SQL（DATE_TRUNC / ::date），SQLite 測試環境不冒煙，線上另行驗證。
 */
$transparencyStats = function (): array {
    return Cache::remember('transparency.stats', 3600, function () {
        $rejectionBreakdown = DB::table('reports')
            ->where('status', 'rejected')
            ->whereNotNull('rejection_reason')
            ->selectRaw('rejection_reason, COUNT(*) as c')
            ->groupBy('rejection_reason')
            ->orderByDesc('c')
            ->pluck('c', 'rejection_reason')
            ->all();

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
};

Route::get('/transparency', fn () => view('pages.transparency', ['stats' => $transparencyStats()]))->name('transparency');
Route::get('/stats', fn () => view('pages.stats', ['stats' => $transparencyStats()]))->name('stats');

Route::get('/contact', fn () => view('pages.contact'))->name('contact');

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
