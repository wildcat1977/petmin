@extends('layouts.app')

@section('title', __('我找垃圾桶 · Petmin'))

@section('content')
<div class="min-h-dvh bg-radar-bg text-slate-100">
    @include('partials.nav')

    <main class="mx-auto max-w-xl px-4 py-6">
        <h1 class="text-2xl font-bold">{{ __('我找垃圾桶') }}</h1>
        <p class="mt-1 text-sm text-slate-300">{{ __('手上有垃圾或狗便便？找最近的清潔箱，太遠就按「好遠！」告訴我們這裡需要桶。') }}</p>

        <div class="mt-5 flex flex-col gap-2 sm:flex-row">
            <button type="button" data-find-locate class="flex-1 rounded-xl bg-emerald-500 px-4 py-3 text-base font-bold text-emerald-950 shadow hover:bg-emerald-400">
                📍 {{ __('用我的位置找桶') }}
            </button>
            <button type="button" data-find-demo class="rounded-xl border border-slate-500 px-4 py-3 text-sm text-slate-200 hover:border-slate-300">
                {{ __('用示範位置（中正區）') }}
            </button>
        </div>

        <p data-find-status class="mt-4 min-h-6 text-sm text-slate-300" aria-live="polite"></p>

        <ol data-find-list class="mt-2 flex flex-col gap-3"></ol>

        <div data-find-far-wrap class="mt-5 hidden">
            <button type="button" data-find-far class="w-full rounded-xl bg-red-500 px-4 py-4 text-lg font-extrabold text-white shadow-lg hover:bg-red-400">
                {{ __('好遠！') }}
            </button>
            <p class="mt-2 text-center text-xs text-slate-400">{{ __('按下會匿名記錄你現在的位置，作為「這附近需要垃圾桶」的參考。') }}</p>
        </div>

        <p class="mt-8 text-xs text-slate-500">
            {{ __('只列出已收錄的公共清潔箱（不含狗便袋箱）；資料可能與現場不同。距離由開源步行路網計算，路網暫時無法使用時改用直線距離估算並標「約」。') }}
        </p>
    </main>

    <div class="px-4 py-6">
        @include('partials.footer-links')
    </div>
</div>

<script>
    (() => {
        // 示範位置：黑客松會場附近（台北市中正區北平東路）
        const DEMO = { lat: 25.0466, lng: 121.5245 };
        const $ = (sel) => document.querySelector(sel);
        const statusEl = $('[data-find-status]');
        const listEl = $('[data-find-list]');
        const farWrap = $('[data-find-far-wrap]');
        const farBtn = $('[data-find-far]');
        let origin = null;
        let nearest = null;
        let mode = 'walking'; // walking | estimated

        const setStatus = (text) => { statusEl.textContent = text; };

        function render(bins) {
            listEl.innerHTML = '';
            bins.forEach((bin, i) => {
                const li = document.createElement('li');
                li.className = 'flex items-center gap-3 rounded-xl bg-radar-card p-4 shadow';
                const info = document.createElement('div');
                info.className = 'min-w-0 flex-1';
                const title = document.createElement('div');
                title.className = 'truncate font-bold';
                title.textContent = `${i === 0 ? '🥇 ' : '🗑️ '}${bin.name || '清潔箱'}`;
                const meta = document.createElement('div');
                meta.className = 'mt-0.5 text-sm text-slate-300';
                const label = mode === 'walking' ? '步行' : '步行約';
                const confirmed = bin.confirmed_days_ago === null ? '' : (bin.confirmed_days_ago === 0 ? '・今天有人確認還在' : `・${bin.confirmed_days_ago} 天前有人確認還在`);
                meta.textContent = `${label} ${bin.distance_m} 公尺・約 ${bin.walk_minutes} 分鐘${confirmed}`;
                info.append(title, meta);
                const go = document.createElement('a');
                go.href = bin.nav_url;
                go.target = '_blank';
                go.rel = 'noopener';
                go.className = 'shrink-0 rounded-lg bg-sky-600 px-3 py-2 text-sm font-bold text-white hover:bg-sky-500';
                go.textContent = '帶我去';
                li.append(info, go);
                listEl.appendChild(li);
            });
        }

        async function find(lat, lng, isDemo) {
            origin = { lat, lng };
            setStatus('找桶中…');
            listEl.innerHTML = '';
            farWrap.classList.add('hidden');
            try {
                const res = await fetch(`/api/map/bins/find?lat=${lat}&lng=${lng}`, { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error(res.status);
                const data = await res.json();
                mode = data.distance_mode;
                nearest = data.bins[0] ?? null;
                render(data.bins);
                const where = isDemo ? '（示範位置）' : '';
                setStatus(data.bins.length
                    ? `離你最近的 ${data.bins.length} 個清潔箱${where}${mode === 'estimated' ? '・路網暫時無法使用，距離為估算' : ''}`
                    : `附近 2 公里內沒有已收錄的清潔箱${where}`);
                farBtn.disabled = false;
                farBtn.textContent = '好遠！';
                farWrap.classList.remove('hidden');
            } catch (e) {
                setStatus('找桶失敗，請稍後再試');
            }
        }

        $('[data-find-locate]').addEventListener('click', () => {
            if (!navigator.geolocation) {
                setStatus('這台裝置無法定位，可以先用示範位置');
                return;
            }
            setStatus('定位中…');
            // 定位失敗不偷偷換成預設座標，避免把地圖中心記成需求位置
            navigator.geolocation.getCurrentPosition(
                (pos) => find(pos.coords.latitude, pos.coords.longitude, false),
                () => setStatus('沒有拿到你的位置（可能沒開定位權限），可以先用示範位置'),
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 },
            );
        });
        $('[data-find-demo]').addEventListener('click', () => find(DEMO.lat, DEMO.lng, true));

        farBtn.addEventListener('click', async () => {
            if (!origin) return;
            farBtn.disabled = true;
            try {
                const res = await fetch('/api/far-signals', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        lat: origin.lat,
                        lng: origin.lng,
                        nearest_bin_id: nearest?.id ?? null,
                        nearest_distance_m: nearest?.distance_m ?? null,
                        distance_mode: mode,
                    }),
                });
                const data = await res.json();
                farBtn.textContent = data.message ?? '收到！';
            } catch (e) {
                farBtn.disabled = false;
                farBtn.textContent = '送出失敗，再按一次';
            }
        });
    })();
</script>
@endsection
