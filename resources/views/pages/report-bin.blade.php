@extends('layouts.app')

@section('title', __('回報垃圾桶狀況 · Petmin'))

@section('content')
<div class="min-h-dvh bg-radar-bg text-slate-100">
    @include('partials.nav')

    {{-- LINE 選單「回報垃圾桶狀況」：找到身邊的桶 → 進打卡頁回報（照片必填、回報後抽扭蛋） --}}
    <main class="mx-auto max-w-xl px-4 py-6">
        <h1 class="text-2xl font-bold">🐾 {{ __('回報垃圾桶狀況') }}</h1>
        <p class="mt-1 text-sm text-slate-300">{{ __('散步時看到附近的垃圾桶，滿了、壞了、臭臭的，或還好好的，都可以回報。每次回報都能抽一張扭蛋卡！') }}</p>

        <div class="mt-5 flex flex-col gap-2 sm:flex-row">
            <button type="button" data-rb-locate class="flex-1 rounded-xl bg-amber-400 px-4 py-3 text-base font-bold text-amber-950 shadow hover:bg-amber-300">
                📍 {{ __('找我身邊的垃圾桶') }}
            </button>
            <button type="button" data-rb-demo class="rounded-xl border border-slate-500 px-4 py-3 text-sm text-slate-200 hover:border-slate-300">
                {{ __('用示範位置（中正區）') }}
            </button>
        </div>

        <p data-rb-status class="mt-4 min-h-6 text-sm text-slate-300" aria-live="polite"></p>
        <ol data-rb-list class="mt-2 flex flex-col gap-3"></ol>

        <p class="mt-8 text-xs text-slate-500">{{ __('垃圾桶上有 Petmin 貼紙的話，直接用手機碰一下或掃 QR Code 也能回報。') }}</p>
    </main>

    <div class="px-4 py-6">
        @include('partials.footer-links')
    </div>
</div>

<script>
    (() => {
        const DEMO = @json(config('petmin.find_bin.demo_point'));
        const $ = (sel) => document.querySelector(sel);
        const statusEl = $('[data-rb-status]');
        const listEl = $('[data-rb-list]');
        const setStatus = (t) => { statusEl.textContent = t; };

        function render(bins) {
            listEl.innerHTML = '';
            bins.forEach((bin) => {
                const li = document.createElement('li');
                li.className = 'flex items-center gap-3 rounded-xl bg-radar-card p-4 shadow';
                const info = document.createElement('div');
                info.className = 'min-w-0 flex-1';
                const title = document.createElement('div');
                title.className = 'truncate font-bold';
                title.textContent = `🗑️ ${bin.name || '清潔箱'}`;
                const meta = document.createElement('div');
                meta.className = 'mt-0.5 text-sm text-slate-300';
                const seen = bin.confirmed_days_ago === null ? '還沒有人回報過' : (bin.confirmed_days_ago === 0 ? '今天有人回報過' : `${bin.confirmed_days_ago} 天前有人回報`);
                meta.textContent = `約 ${bin.distance_m} 公尺・${seen}`;
                info.append(title, meta);
                const go = document.createElement('a');
                go.href = `/b/${encodeURIComponent(bin.code)}?via=line`;
                go.className = 'shrink-0 rounded-lg bg-amber-400 px-3 py-2 text-sm font-bold text-amber-950 hover:bg-amber-300';
                go.textContent = '回報這個桶';
                li.append(info, go);
                listEl.appendChild(li);
            });
        }

        async function load(lat, lng, isDemo) {
            setStatus('找附近的垃圾桶…');
            listEl.innerHTML = '';
            try {
                const res = await fetch(`/api/map/bins/find?lat=${lat}&lng=${lng}`, { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error(res.status);
                const data = await res.json();
                render(data.bins);
                setStatus(data.bins.length ? `你身邊的 ${data.bins.length} 個垃圾桶${isDemo ? '（示範位置）' : ''}，選一個回報` : '附近 2 公里內沒有已收錄的垃圾桶');
            } catch (e) {
                setStatus('讀取失敗，請稍後再試');
            }
        }

        $('[data-rb-locate]').addEventListener('click', () => {
            if (!navigator.geolocation) { setStatus('這台裝置無法定位，可以先用示範位置'); return; }
            setStatus('定位中…');
            navigator.geolocation.getCurrentPosition(
                (pos) => load(pos.coords.latitude, pos.coords.longitude, false),
                () => setStatus('沒有拿到你的位置（可能沒開定位權限），可以先用示範位置'),
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 },
            );
        });
        $('[data-rb-demo]').addEventListener('click', () => load(DEMO.lat, DEMO.lng, true));
    })();
</script>
@endsection
