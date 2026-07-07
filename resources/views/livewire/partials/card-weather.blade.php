@if ($this->weather)
    <div class="mt-2 flex items-end gap-2">
        <span class="text-5xl font-black">{{ $this->weather['temp'] !== null ? round($this->weather['temp']) : '--' }}<span class="text-2xl align-top">°C</span></span>
        <div class="pb-1 text-xs text-slate-400">
            <p>臺北測站</p>
            <p class="text-[10px] text-slate-600">{{ \Illuminate\Support\Carbon::parse($this->weather['observed_at'])->timezone('Asia/Taipei')->format('H:i') }} 觀測</p>
        </div>
    </div>
    <div class="mt-3 grid grid-cols-2 gap-2">
        @foreach ([
            ['label' => '時雨量', 'value' => $this->weather['rain_1h'], 'unit' => 'mm', 'class' => 'text-cyan-300'],
            ['label' => '風速', 'value' => $this->weather['wind_speed'], 'unit' => 'm/s', 'class' => 'text-emerald-300'],
            ['label' => '累積雨量 24H', 'value' => $this->rainAccumulated, 'unit' => 'mm', 'class' => 'text-sky-300'],
            ['label' => '能見度', 'value' => $this->weather['visibility'], 'unit' => 'km', 'class' => 'text-indigo-300'],
        ] as $stat)
            <div class="rounded-lg border border-white/10 bg-black/30 p-2">
                <p class="text-[10px] text-slate-500">{{ $stat['label'] }}</p>
                <p class="text-xl font-bold {{ $stat['class'] }}">{{ $stat['value'] !== null ? $stat['value'] : '--' }}<span class="ml-0.5 text-[10px] text-slate-500">{{ $stat['unit'] }}</span></p>
            </div>
        @endforeach
    </div>
@else
    {{-- 驗收 §1-1：外部源中斷顯示明確狀態，而非壞版面 --}}
    <div class="mt-3 rounded-lg border border-yellow-500/30 bg-yellow-500/10 p-3 text-xs text-yellow-200">
        ⚠ CWA 資料更新中斷<br>
        <span class="text-[10px] text-yellow-200/60">
            最後更新：{{ $this->cwaLastSuccessAt ? \Illuminate\Support\Carbon::parse($this->cwaLastSuccessAt)->timezone('Asia/Taipei')->format('m/d H:i') : '尚無成功紀錄' }}
        </span>
    </div>
@endif
