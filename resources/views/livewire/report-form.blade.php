<?php

use App\Jobs\ModerateReportImage;
use App\Models\Report;
use App\Models\ReportCategory;
use App\Models\TrashBin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public bool $open = false;
    public bool $exifLocation = false;

    public string $category = '';

    public ?int $extraValue = null;

    public $photo = null;

    public ?float $latitude = null;

    public ?float $longitude = null;

    public string $address = '';

    public string $description = '';

    public bool $submitted = false;

    /** 吸附到的既有桶（30m 內最近；need_bin 類別送出時忽略） */
    public ?int $trashBinId = null;

    public string $snappedBinName = '';

    public string $snappedBinKind = '';

    public ?int $snappedBinDistance = null;

    /** 從桶 popup「回報這個桶」進來：表單帶桶位座標，小地圖不再抓 GPS */
    public bool $presetLocation = false;

    public function mount(): void
    {
        $this->category = ReportCategory::defaultSlug();
    }

    public function rules(): array
    {
        $extra = ReportCategory::bySlug($this->category)?->extra_field;

        return [
            'category'    => ['required', 'in:'.implode(',', ReportCategory::activeSlugs())],
            'extraValue'  => $extra
                ? ['nullable', 'integer', 'between:'.($extra['min'] ?? 0).','.($extra['max'] ?? 9999)]
                : ['nullable', 'integer'],
            'photo'       => ['nullable', 'image', 'max:20480'],
            'latitude'    => ['required', 'numeric', 'between:-90,90'],
            'longitude'   => ['required', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.image' => __('請上傳圖片檔案。'),
            'photo.max'   => __('照片檔案不得超過 20MB。'),
        ];
    }

    /**
     * 由首頁按鈕或桶 popup 觸發；未登入則改開啟登入引導。
     * 帶 binId 時預先吸附該桶並把位置定在桶上。
     */
    #[On('open-report-form')]
    public function show(?int $binId = null): void
    {
        if (! Auth::check()) {
            $this->dispatch('open-auth-onboarding');
            return;
        }
        $this->reset(['photo', 'description', 'submitted', 'extraValue', 'exifLocation',
            'trashBinId', 'snappedBinName', 'snappedBinKind', 'snappedBinDistance', 'presetLocation']);
        $this->category = ReportCategory::defaultSlug();

        if ($binId && ($bin = TrashBin::find($binId))) {
            $this->latitude = (float) $bin->latitude;
            $this->longitude = (float) $bin->longitude;
            $this->address = $bin->district ? "台北市 {$bin->district}" : '';
            $this->presetLocation = true;
            $this->setSnappedBin($bin->id, $bin->address ?? '', $bin->kind, 0);
            // 從桶進來，預設分類改成「桶滿了」（最常見的回報）
            if (in_array(Report::CATEGORY_FULL, ReportCategory::activeSlugs(), true)) {
                $this->category = Report::CATEGORY_FULL;
            }
        }
        $this->open = true;
    }

    public function setSnappedBin(int $id, string $name, string $kind, ?int $distance = null): void
    {
        $this->trashBinId = $id;
        $this->snappedBinName = $name;
        $this->snappedBinKind = $kind;
        $this->snappedBinDistance = $distance;
    }

    public function clearSnappedBin(): void
    {
        $this->reset(['trashBinId', 'snappedBinName', 'snappedBinKind', 'snappedBinDistance']);
    }

    public function close(): void
    {
        $this->open      = false;
        $this->submitted = false;
    }

    public function updatedCategory(): void
    {
        if (! ReportCategory::bySlug($this->category)?->extra_field) {
            $this->extraValue = null;
        }
    }

    public function setLocation(float $lat, float $lng): void
    {
        $this->latitude = $lat;
        $this->longitude = $lng;
    }

    public function setAddress(string $address): void
    {
        $this->address = $address;
    }

    public function setLocationFromExif(float $lat, float $lng): void
    {
        $this->latitude = $lat;
        $this->longitude = $lng;
        $this->exifLocation = true;
    }

    public function submit(): void
    {
        if (Auth::user()?->is_banned) {
            $this->addError('description', __('您的帳號已被停用，無法提交回報。如有疑問請聯絡管理員。'));
            return;
        }

        if ($error = $this->rateLimitError()) {
            $this->addError('description', $error);
            return;
        }

        $this->validate();

        // 寫入即回應：照片僅落地重編碼，AI 審核與地址反查不在請求生命週期內
        $filename = null;
        if ($this->photo) {
            $manager  = new ImageManager(new GdDriver());
            $image    = $manager->decode($this->photo->getRealPath());
            $filename = 'reports/' . Str::uuid() . '.jpg';
            $jpeg     = $image->encode(new JpegEncoder(quality: 85));
            Storage::disk('public')->put($filename, $jpeg);
        }

        // 無照片時內容無需 AI 審核；有照片時照片通過審核前不公開（點位照常顯示）
        $status = $this->photo
            ? Report::STATUS_PENDING
            : (config('petmin.auto_approve') ? Report::STATUS_APPROVED : Report::STATUS_PENDING);

        // 「這裡需要桶」本來就是沒桶的地方，不掛在既有桶上；其餘分類掛到吸附的桶（若有）
        $trashBinId = $this->category === Report::CATEGORY_NEED_BIN ? null : $this->trashBinId;
        if ($trashBinId && ! TrashBin::whereKey($trashBinId)->exists()) {
            $trashBinId = null;
        }

        $report = Report::create([
            'user_id'        => Auth::id(),
            'category'       => $this->category,
            'trash_bin_id'   => $trashBinId,
            'extra_value'    => ReportCategory::bySlug($this->category)?->extra_field ? $this->extraValue : null,
            'latitude'       => $this->latitude,
            'longitude'      => $this->longitude,
            'address'        => $this->address ?: null,
            'image_path'     => $filename,
            'description'    => $this->description ?: null,
            'status'         => $status,
        ]);

        if ($filename) {
            ModerateReportImage::dispatch($report->id)->afterCommit();
        }

        $this->submitted = true;
        $this->dispatch('report-submitted');
    }

    /** 反濫用：cooldown + 每帳號每小時上限（spec §6.1-5） */
    private function rateLimitError(): ?string
    {
        $cooldown = (int) config('petmin.report_cooldown_seconds');
        $lastAt = Report::where('user_id', Auth::id())->latest()->value('created_at');

        if ($lastAt && $lastAt->diffInSeconds(now()) < $cooldown) {
            $wait = $cooldown - (int) $lastAt->diffInSeconds(now());

            return __('回報間隔太短，請 :s 秒後再試。', ['s' => max($wait, 1)]);
        }

        $hourlyLimit = (int) config('petmin.report_hourly_limit');
        $lastHour = Report::where('user_id', Auth::id())
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($lastHour >= $hourlyLimit) {
            return __('已達每小時回報上限（:n 筆），請稍後再回報。', ['n' => $hourlyLimit]);
        }

        return null;
    }
}; ?>

<div>
    @if ($open)
        @php $disclaimerMode = config('petmin.disclaimer.report_mode', 'every'); @endphp
        @if ($disclaimerMode !== 'off' && ! $submitted)
            {{-- 回報免責聲明（spec §11.3）：every=每次開表單重跳；session=每 session 一次 --}}
            <div x-data="{ ack: @js($disclaimerMode) === 'session' && !! sessionStorage.getItem('petmin:disclaimer:report') }"
                 x-show="! ack"
                 x-cloak
                 class="fixed inset-0 z-[1100] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm">
                <div class="w-full max-w-md rounded-3xl bg-[#161b22] p-6 text-slate-100 shadow-2xl ring-1 ring-white/10">
                    <h2 class="text-lg font-bold">⚠️ {{ __('使用前請注意') }}</h2>
                    <p class="mt-3 text-sm leading-relaxed text-slate-300">
                        {{ __('本地圖彙整環保局開放資料與市民回報，僅供大眾參考，不代表市府公告。清運需求請同時撥打 1999 或使用台北市民當家熱線。回報時請注意自身與毛孩安全。') }}
                    </p>
                    <button type="button"
                            @click="sessionStorage.setItem('petmin:disclaimer:report', '1'); ack = true"
                            class="mt-5 w-full rounded-xl bg-cyan-600 px-4 py-3 font-semibold hover:bg-cyan-500">
                        {{ __('我知道了，開始回報') }}
                    </button>
                </div>
            </div>
        @endif
        <div class="fixed inset-0 z-[1000] flex items-end justify-center bg-black/70 backdrop-blur-sm sm:items-center"
             wire:click.self="close">
            {{-- max-h + overflow-y-auto：矮螢幕（如 iPhone SE）表單比視窗高時可內部捲動，
                 不會因 items-end 把頂端頂出視窗外而選不到分類（v0.3 五次回饋 item 1）。 --}}
            <div class="max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-t-3xl bg-[#161b22] p-6 text-slate-100 shadow-2xl ring-1 ring-white/10 sm:rounded-3xl">
                @if ($submitted)
                    <div class="py-10 text-center">
                        <div class="mx-auto mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-cyan-600/20">
                            <span class="text-4xl">🐾</span>
                        </div>
                        <h2 class="text-xl font-bold">{{ __('回報成功') }}</h2>
                        <p class="mt-2 text-sm text-slate-400">{{ __('已上圖。審核通過後會加點；同一個桶若有其他人也回報，會自動升級為「多方佐證」。') }}</p>
                        <button wire:click="close"
                                class="mt-6 w-full rounded-xl bg-cyan-600 px-4 py-3 font-semibold">
                            {{ __('關閉') }}
                        </button>
                    </div>
                @else
                    <div class="flex items-start justify-between">
                        <h2 class="text-xl font-bold">{{ __('🐾 回報垃圾桶狀況') }}</h2>
                        <button wire:click="close" class="text-slate-500 hover:text-slate-200">✕</button>
                    </div>

                    {{-- 照片壓縮、上傳完成前不可送出：否則回報建立時照片還沒到，那筆就沒有照片（使用者卻以為附上了）。
                         除了按鈕 disabled，送出時也擋：其他 Livewire 請求（如 EXIF 帶入位置）結束時 wire:loading 會拿掉 disabled --}}
                    <form wire:submit="submit" class="mt-4 space-y-4"
                          x-data="{ photoBusy: false }"
                          x-on:photo-busy.window="photoBusy = $event.detail"
                          x-on:submit.capture="if (photoBusy) { $event.preventDefault(); $event.stopImmediatePropagation(); }">
                        {{-- 狀況分類（必選；來源 report_categories，配色對應地圖圖例） --}}
                        <div>
                            <label class="block text-xs font-medium text-slate-400">{{ __('發生什麼事') }}</label>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                @foreach (\App\Models\ReportCategory::activeOrdered() as $cat)
                                    <button type="button"
                                            wire:click="$set('category', '{{ $cat->slug }}')"
                                            @class([
                                                'flex items-center justify-center gap-2 rounded-xl border px-3 py-3 text-sm font-semibold transition',
                                                'border-white/10 bg-black/30 text-slate-400 hover:border-white/20' => $category !== $cat->slug,
                                            ])
                                            @if ($category === $cat->slug)
                                                style="border-color: {{ $cat->color }}99; background: {{ $cat->color }}26; color: {{ $cat->color }}; box-shadow: 0 0 18px {{ $cat->color }}59"
                                            @endif>
                                        {{ $cat->emoji }} {{ __($cat->name) }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        {{-- 分類附加數值欄（extra_field 有設定才顯示，選填） --}}
                        @php $extraField = \App\Models\ReportCategory::bySlug($category)?->extra_field; @endphp
                        @if ($extraField)
                            <div>
                                <label class="block text-xs font-medium text-slate-400">{{ __($extraField['label']) }} <span class="text-slate-600">（{{ $extraField['unit'] ?? '' }}，{{ __('選填') }}）</span></label>
                                @if (! empty($extraField['presets']))
                                    <div class="mt-2 grid grid-cols-5 gap-1.5">
                                        @foreach ($extraField['presets'] as $preset)
                                            <button type="button"
                                                    wire:click="$set('extraValue', {{ $extraValue === $preset['value'] ? 'null' : $preset['value'] }})"
                                                    class="rounded-lg border px-1 py-2 text-[11px] font-medium transition
                                                           {{ $extraValue === $preset['value']
                                                               ? 'border-cyan-400/60 bg-cyan-500/15 text-cyan-100'
                                                               : 'border-white/10 bg-black/30 text-slate-400 hover:border-white/20' }}">
                                                {{ __($preset['hint']) }}<br><span class="opacity-60">{{ $preset['value'] }}{{ $extraField['unit'] ?? '' }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                                <input type="number" wire:model="extraValue"
                                       min="{{ $extraField['min'] ?? 0 }}" max="{{ $extraField['max'] ?? 9999 }}"
                                       class="mt-2 w-full rounded-lg border border-white/10 bg-black/30 p-2 text-sm"
                                       placeholder="{{ __($extraField['label']) }}">
                                @error('extraValue') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        <div x-data="{ preview: null, setPreview(files) { const f = files?.[0]; if (!f) return; const old = this.preview; this.preview = URL.createObjectURL(f); if (old) URL.revokeObjectURL(old); } }">
                            <label class="block text-xs font-medium text-slate-400">{{ __('📸 現場照片') }} <span class="text-slate-600">（{{ __('選填；拍到桶或現場最好，不用拍到人') }}）</span></label>
                            {{-- 縮圖預覽 --}}
                            <div x-show="preview" x-cloak class="mt-2 flex items-center gap-3">
                                <img :src="preview" alt="預覽" class="h-20 w-20 rounded-lg object-cover ring-1 ring-white/20">
                                <button type="button"
                                        @click="preview = null"
                                        class="text-xs text-slate-500 hover:text-slate-300">{{ __('✕ 重選') }}</button>
                            </div>
                            {{-- 不用 wire:model：它在 change 當下就上傳原圖，前端壓縮完的小檔永遠送不出去
                                 （2026-10-01 審查發現）。改由 onPhotoChange 壓縮後以 $wire.upload 上傳一次 --}}
                            <input type="file" accept="image/*"
                                   x-on:change="setPreview($el.files); window.onPhotoChange($el, $wire)"
                                   class="mt-2 w-full rounded-lg border border-white/10 bg-black/30 p-2 text-sm">
                            @error('photo') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-400">{{ __('📍 位置') }} <span class="text-slate-600">（{{ __('移動地圖即可調整；靠近既有的桶會自動吸附') }}）</span></label>
                            <div wire:ignore
                                 x-data="{ destroy() { this.$el.__pinMap?.remove(); this.$el.__exifCleanup?.(); } }"
                                 x-init="initPinMap($el, $wire)"
                                 data-nearby-url="{{ route('api.map.bins.nearby') }}"
                                 @if ($presetLocation && $latitude && $longitude) data-preset-lat="{{ $latitude }}" data-preset-lng="{{ $longitude }}" @endif
                                 class="mt-2 h-48 w-full overflow-hidden rounded-lg border border-white/10 bg-black/40"></div>
                            {{-- 吸附到的桶：need_bin 類別本來就是沒桶的地方，不顯示 --}}
                            @if ($trashBinId && $category !== Report::CATEGORY_NEED_BIN)
                                <div class="mt-2 flex items-center justify-between gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-xs text-emerald-200">
                                    <span class="min-w-0 truncate">📌 {{ __('吸附到：:name', ['name' => ($snappedBinKind === TrashBin::KIND_DOG_BAG_BOX ? '🐕 ' : '🗑️ ').$snappedBinName]) }}@if ($snappedBinDistance !== null && $snappedBinDistance > 0)<span class="text-emerald-400/70">（{{ $snappedBinDistance }} m）</span>@endif</span>
                                    <button type="button" wire:click="clearSnappedBin" class="shrink-0 text-emerald-400/70 hover:text-emerald-200">{{ __('取消') }}</button>
                                </div>
                            @endif
                            <p class="mt-1 text-[11px] text-slate-500">
                                @if ($exifLocation && $latitude && $longitude)
                                    {{ __('📷 從照片 EXIF 取得：:coords（可移動地圖微調）', ['coords' => number_format($latitude, 5).', '.number_format($longitude, 5)]) }}
                                @elseif ($latitude && $longitude)
                                    {{ __('已鎖定：:coords（可移動地圖調整）', ['coords' => number_format($latitude, 5).', '.number_format($longitude, 5)]) }}
                                @else
                                    {{ __('正在抓取定位…') }}
                                @endif
                            </p>
                            @error('latitude') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-400">{{ __('📝 補充說明') }}
                                <span class="text-slate-600">（{{ __('選填') }}）</span>
                            </label>
                            <textarea wire:model="description" rows="3"
                                      placeholder="{{ __('例如：遛狗必經，袋子只能帶回家；桶旁堆了三袋家用垃圾…') }}"
                                      class="mt-2 w-full rounded-lg border border-white/10 bg-black/30 p-2 text-sm placeholder:text-slate-600"></textarea>
                            @error('description') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit"
                                wire:loading.attr="disabled"
                                x-bind:disabled="photoBusy"
                                class="w-full rounded-xl bg-cyan-600 px-4 py-3 font-bold text-white shadow-[0_0_20px_rgba(8,145,178,0.5)] active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span wire:loading.remove>
                                <span x-show="!photoBusy">{{ __('送出回報') }}</span>
                                <span x-show="photoBusy" x-cloak>{{ __('⏳ 照片處理中…') }}</span>
                            </span>
                            <span wire:loading>{{ __('⏳ 回報傳送中…') }}</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    @endif
</div>
