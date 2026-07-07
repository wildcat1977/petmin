<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">資料源健康狀態</x-slot>

        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
            @foreach ($sources as $key => $source)
                <span class="flex items-center gap-1.5" title="{{ $key }}{{ $source['last_error'] ? '：'.$source['last_error']['message'] : '' }}">
                    <span @class([
                        'h-2.5 w-2.5 rounded-full',
                        'bg-emerald-500' => $source['status'] === \App\Services\DataSourceHealth::STATUS_OK,
                        'bg-yellow-400' => $source['status'] === \App\Services\DataSourceHealth::STATUS_DELAYED,
                        'bg-rose-500' => $source['status'] === \App\Services\DataSourceHealth::STATUS_DOWN,
                    ])></span>
                    <span class="text-gray-600 dark:text-gray-300">{{ $source['label'] }}</span>
                    <span class="font-mono text-xs text-gray-400" data-status="{{ $source['status'] }}">
                        {{ $source['last_success_at'] ? \Illuminate\Support\Carbon::parse($source['last_success_at'])->timezone('Asia/Taipei')->format('H:i') : '—' }}
                    </span>
                </span>
            @endforeach

            <span class="ml-auto flex items-center gap-4 font-mono text-sm">
                <span>今日回報 <b>{{ $today_reports }}</b></span>
                <span>官方核實 <b class="text-emerald-500">{{ $official }}</b></span>
                <span>Queue <b @class(['text-yellow-500' => $queue_depth > 100])>{{ $queue_depth }}</b></span>
            </span>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
