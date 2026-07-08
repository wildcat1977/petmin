@php
    $badge = fn (int $s) => match ($s) {
        1 => ['正常', 'bg-emerald-500/20 text-emerald-300'],
        2 => ['調整', 'bg-amber-500/20 text-amber-300'],
        default => ['停駛', 'bg-rose-500/20 text-rose-300'],
    };
    $sysLabel = ['metro' => '捷運', 'gondola' => '貓空纜車', 'bus' => '公車'];
@endphp
@if (! empty($transit))
    <section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-4">
        <h2 class="mb-3 text-lg font-semibold text-slate-200">🚇 大眾交通</h2>
        @foreach ($transit as $system => $rows)
            @foreach ($rows as $r)
                @php [$txt, $cls] = $badge($r['status']); @endphp
                <div class="flex items-center justify-between border-b border-white/5 py-1.5 text-sm last:border-0">
                    <span class="text-slate-300">{{ $sysLabel[$system] ?? $system }}{{ $r['scope'] ? '・'.$r['scope'] : '' }}</span>
                    <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $cls }}">{{ $txt }}</span>
                </div>
            @endforeach
        @endforeach
    </section>
@endif
