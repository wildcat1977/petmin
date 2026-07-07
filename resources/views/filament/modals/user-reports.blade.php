{{--
  同帳號回報展開 modal
  Props: $reports (Collection<Report>), $currentId (int)
--}}
@php
use Illuminate\Support\Facades\Storage;
use App\Filament\Resources\Reports\ReportResource;

$statusColor = fn (string $s) => match($s) {
    "approved" => "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
    "rejected" => "bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400",
    default    => "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300",
};
$reasonsJson = json_encode(\App\Models\Report::REJECTION_REASONS, JSON_UNESCAPED_UNICODE);
@endphp

<div class="text-sm">
    @foreach($reports as $report)
        {{-- x-data 包住整列 + 拒絕展開面板，確保 Alpine scope 連通 --}}
        <div
            x-data="{
                rejectOpen: false,
                reason: '',
                newStatus: '{{ $report->status }}',
                newReason: '{{ $report->rejection_reason }}',
                reasons: {!! $reasonsJson !!},
                statusLabel(s) {
                    return {
                        pending:  '待審核',
                        approved: '✅ 已核准（照片公開）',
                        rejected: '❌ 已拒絕'
                    }[s] ?? s;
                }
            }"
            class="border-b border-gray-100 dark:border-gray-700/60 last:border-0"
        >
            {{-- 主列（橫向） --}}
            <div class="flex items-start gap-3 py-3 px-1 {{ $report->id === $currentId ? 'bg-primary-50/60 dark:bg-primary-950/20 rounded' : '' }}">

                {{-- 縮圖（固定 64×64，不跟著 flex 伸縮） --}}
                <div class="w-16 h-16 flex-none overflow-hidden rounded ring-1 ring-black/10">
                    @if($report->image_path)
                        <a href="{{ Storage::disk('public')->url($report->image_path) }}" target="_blank" class="block w-full h-full">
                            <img src="{{ Storage::disk('public')->url($report->image_path) }}"
                                 alt="照片"
                                 class="w-full h-full object-cover">
                        </a>
                    @else
                        <div class="w-full h-full flex items-center justify-center bg-gray-100 dark:bg-gray-800 text-[10px] text-gray-400 leading-tight text-center">
                            無<br>照片
                        </div>
                    @endif
                </div>

                {{-- 文字資訊（佔滿剩餘寬度） --}}
                <div class="flex-1 min-w-0 flex flex-col gap-0.5">
                    <div class="flex flex-wrap items-center gap-1.5 text-xs">
                        <span class="font-mono font-semibold text-gray-700 dark:text-gray-300">#{{ $report->id }}</span>
                        @if($report->id === $currentId)
                            <span class="rounded bg-primary-100 px-1.5 py-0.5 text-[10px] font-medium text-primary-700 dark:bg-primary-900 dark:text-primary-300">當前</span>
                        @endif
                        <span>{{ \App\Models\ReportCategory::labels()[$report->category] ?? $report->category }}</span>
                        <span class="ml-auto text-gray-400 flex-none">{{ $report->created_at->format('m/d H:i') }}</span>
                    </div>

                    <div class="flex items-center gap-1.5 flex-wrap">
                        <span class="rounded px-1.5 py-0.5 text-[11px] font-medium {{ $statusColor($report->status) }}"
                              x-text="statusLabel(newStatus)"></span>
                        <span x-show="newReason" x-cloak class="text-[10px] text-gray-500"
                              x-text="reasons[newReason] ?? newReason"></span>
                    </div>

                    @if($report->address)
                        <div class="truncate text-[11px] text-gray-500">📍 {{ $report->address }}</div>
                    @endif
                    @if($report->description)
                        <div class="truncate text-[11px] text-gray-600 dark:text-gray-400">
                            {{ \Illuminate\Support\Str::limit($report->description, 50) }}
                        </div>
                    @endif
                </div>

                {{-- 操作按鈕（靠右，固定寬度） --}}
                <div class="flex-none flex flex-col items-end gap-1.5">
                    <button
                        wire:click="updateReportStatus({{ $report->id }}, 'approved')"
                        x-on:click="newStatus = 'approved'; newReason = ''; rejectOpen = false"
                        x-bind:disabled="newStatus === 'approved'"
                        x-bind:class="newStatus === 'approved' ? 'opacity-40 cursor-default' : 'hover:bg-green-600'"
                        class="rounded bg-green-500 px-2.5 py-1 text-xs font-medium text-white transition active:scale-95 w-14 text-center">
                        核准
                    </button>
                    <button
                        x-on:click="rejectOpen = !rejectOpen"
                        x-bind:class="newStatus === 'rejected' ? 'bg-gray-400 dark:bg-gray-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-600'"
                        class="rounded px-2.5 py-1 text-xs font-medium transition active:scale-95 w-14 text-center">
                        拒絕
                    </button>
                    <a href="{{ ReportResource::getUrl('edit', ['record' => $report->id]) }}"
                       target="_blank"
                       class="rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 transition w-14 text-center">
                        編輯
                    </a>
                </div>
            </div>

            {{-- 拒絕原因展開列（在主列下方，同 x-data scope） --}}
            <div x-show="rejectOpen" x-cloak
                 class="flex flex-wrap items-center gap-2 bg-gray-50 dark:bg-gray-800/50 px-2 py-2 text-xs rounded-b mb-1">
                <span class="text-gray-500 flex-none">原因：</span>
                <select x-model="reason"
                        class="flex-1 min-w-0 rounded border border-gray-300 bg-white px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                    <option value="">選擇原因…</option>
                    @foreach(\App\Models\Report::REJECTION_REASONS as $rKey => $rLabel)
                        <option value="{{ $rKey }}">{{ $rLabel }}</option>
                    @endforeach
                </select>
                <button
                    x-on:click="if(reason){ $wire.updateReportStatus({{ $report->id }}, 'rejected', reason); newStatus='rejected'; newReason=reason; rejectOpen=false }"
                    x-bind:disabled="!reason"
                    class="flex-none rounded bg-gray-600 px-2.5 py-1 font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-40 active:scale-95">
                    確認
                </button>
                <button x-on:click="rejectOpen = false"
                        class="flex-none text-gray-400 hover:text-gray-600">
                    取消
                </button>
            </div>
        </div>
    @endforeach
</div>
