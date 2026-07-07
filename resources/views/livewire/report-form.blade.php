<?php

use App\Jobs\ModerateReportImage;
use App\Models\Report;
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

    public string $category = Report::CATEGORY_FLOOD;

    public ?int $extraValue = null;

    public $photo = null;

    public ?float $latitude = null;

    public ?float $longitude = null;

    public string $address = '';

    public string $description = '';

    public bool $submitted = false;

    public function rules(): array
    {
        return [
            'category'     => ['required', 'in:'.implode(',', Report::CATEGORIES)],
            'extraValue' => ['nullable', 'integer', 'between:0,500'],
            'photo'        => ['nullable', 'image', 'max:20480'],
            'latitude'     => ['required', 'numeric', 'between:-90,90'],
            'longitude'    => ['required', 'numeric', 'between:-180,180'],
            'description'  => ['nullable', 'string', 'max:500'],
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
     * 由首頁按鈕觸發；未登入則改開啟登入引導
     */
    #[On('open-report-form')]
    public function show(): void
    {
        if (! Auth::check()) {
            $this->dispatch('open-auth-onboarding');
            return;
        }
        $this->reset(['photo', 'description', 'submitted', 'extraValue']);
        $this->category = Report::CATEGORY_FLOOD;
        $this->open = true;
    }

    public function close(): void
    {
        $this->open      = false;
        $this->submitted = false;
    }

    public function updatedCategory(): void
    {
        if ($this->category !== Report::CATEGORY_FLOOD) {
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
            : (config('hazdar.auto_approve') ? Report::STATUS_APPROVED : Report::STATUS_PENDING);

        $report = Report::create([
            'user_id'        => Auth::id(),
            'category'       => $this->category,
            'extra_value'    => $this->category === Report::CATEGORY_FLOOD ? $this->extraValue : null,
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
        $cooldown = (int) config('hazdar.report_cooldown_seconds');
        $lastAt = Report::where('user_id', Auth::id())->latest()->value('created_at');

        if ($lastAt && $lastAt->diffInSeconds(now()) < $cooldown) {
            $wait = $cooldown - (int) $lastAt->diffInSeconds(now());

            return __('回報間隔太短，請 :s 秒後再試。', ['s' => max($wait, 1)]);
        }

        $hourlyLimit = (int) config('hazdar.report_hourly_limit');
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
        @php $disclaimerMode = config('hazdar.disclaimer.report_mode', 'every'); @endphp
        @if ($disclaimerMode !== 'off' && ! $submitted)
            {{-- 回報免責聲明（spec §11.3）：every=每次開表單重跳；session=每 session 一次 --}}
            <div x-data="{ ack: @js($disclaimerMode) === 'session' && !! sessionStorage.getItem('hazdar:disclaimer:report') }"
                 x-show="! ack"
                 x-cloak
                 class="fixed inset-0 z-[1100] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm">
                <div class="w-full max-w-md rounded-3xl bg-[#161b22] p-6 text-slate-100 shadow-2xl ring-1 ring-white/10">
                    <h2 class="text-lg font-bold">⚠️ {{ __('使用前請注意') }}</h2>
                    <p class="mt-3 text-sm leading-relaxed text-slate-300">
                        {{ __('本地圖彙整網友回報與氣象局雨量回報所得之災情資訊，僅供大眾參考，不代表市府公告，緊急事故請直接撥打119/1999，切勿僅依賴本平台等待救援。希望市民朋友保護自身安全，如非必要請勿出門。') }}
                    </p>
                    <button type="button"
                            @click="sessionStorage.setItem('hazdar:disclaimer:report', '1'); ack = true"
                            class="mt-5 w-full rounded-xl bg-cyan-600 px-4 py-3 font-semibold hover:bg-cyan-500">
                        {{ __('我知道了，開始回報') }}
                    </button>
                </div>
            </div>
        @endif
        <div class="fixed inset-0 z-[1000] flex items-end justify-center bg-black/70 backdrop-blur-sm sm:items-center"
             wire:click.self="close">
            <div class="w-full max-w-md rounded-t-3xl bg-[#161b22] p-6 text-slate-100 shadow-2xl ring-1 ring-white/10 sm:rounded-3xl">
                @if ($submitted)
                    <div class="py-10 text-center">
                        <div class="mx-auto mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-cyan-600/20">
                            <span class="text-4xl">📡</span>
                        </div>
                        <h2 class="text-xl font-bold">{{ __('回報成功') }}</h2>
                        <p class="mt-2 text-sm text-slate-400">{{ __('災情已上圖。鄰近若有其他市民回報相同災情，將自動升級為「多方佐證」。') }}</p>
                        <button wire:click="close"
                                class="mt-6 w-full rounded-xl bg-cyan-600 px-4 py-3 font-semibold">
                            {{ __('關閉') }}
                        </button>
                    </div>
                @else
                    <div class="flex items-start justify-between">
                        <h2 class="text-xl font-bold">{{ __('⚠️ 回報災情') }}</h2>
                        <button wire:click="close" class="text-slate-500 hover:text-slate-200">✕</button>
                    </div>

                    <form wire:submit="submit" class="mt-4 space-y-4">
                        {{-- 災情分類（必選，四鈕；配色對應地圖圖例） --}}
                        <div>
                            <label class="block text-xs font-medium text-slate-400">{{ __('災情分類') }}</label>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                @foreach ([
                                    \App\Models\Report::CATEGORY_FLOOD => ['label' => __('💧 淹水'), 'on' => 'border-sky-400/60 bg-sky-500/15 text-sky-200 shadow-[0_0_18px_rgba(56,189,248,0.35)]'],
                                    \App\Models\Report::CATEGORY_ROAD_BLOCKED => ['label' => __('🚧 道路中斷'), 'on' => 'border-orange-400/60 bg-orange-500/15 text-orange-200 shadow-[0_0_18px_rgba(251,146,60,0.35)]'],
                                    \App\Models\Report::CATEGORY_POWER_OUTAGE => ['label' => __('⚡ 停電'), 'on' => 'border-yellow-400/60 bg-yellow-500/15 text-yellow-200 shadow-[0_0_18px_rgba(250,204,21,0.35)]'],
                                    \App\Models\Report::CATEGORY_STRUCTURAL_DAMAGE => ['label' => __('🏚️ 結構受損'), 'on' => 'border-rose-400/60 bg-rose-500/15 text-rose-200 shadow-[0_0_18px_rgba(244,63,94,0.35)]'],
                                ] as $value => $opt)
                                    <button type="button"
                                            wire:click="$set('category', '{{ $value }}')"
                                            class="flex items-center justify-center gap-2 rounded-xl border px-3 py-3 text-sm font-semibold transition
                                                   {{ $category === $value ? $opt['on'] : 'border-white/10 bg-black/30 text-slate-400 hover:border-white/20' }}">
                                        {{ $opt['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        {{-- 淹水深度（僅淹水顯示，選填） --}}
                        @if ($category === \App\Models\Report::CATEGORY_FLOOD)
                            <div>
                                <label class="block text-xs font-medium text-slate-400">{{ __('💧 目測水深') }} <span class="text-slate-600">（{{ __('選填') }}）</span></label>
                                <div class="mt-2 grid grid-cols-5 gap-1.5">
                                    @foreach ([10 => __('腳踝'), 30 => __('小腿'), 50 => __('及膝'), 100 => __('及腰'), 150 => __('一層樓')] as $cm => $label)
                                        <button type="button"
                                                wire:click="$set('extraValue', {{ $extraValue === $cm ? 'null' : $cm }})"
                                                class="rounded-lg border px-1 py-2 text-[11px] font-medium transition
                                                       {{ $extraValue === $cm
                                                           ? 'border-sky-400/70 bg-sky-500/20 text-sky-100'
                                                           : 'border-white/10 bg-black/30 text-slate-400 hover:border-white/20' }}">
                                            {{ $label }}<br><span class="opacity-60">{{ $cm }}cm</span>
                                        </button>
                                    @endforeach
                                </div>
                                @error('extraValue') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        <div x-data="{ preview: null, setPreview(files) { const f = files?.[0]; if (!f) return; const old = this.preview; this.preview = URL.createObjectURL(f); if (old) URL.revokeObjectURL(old); } }">
                            <label class="block text-xs font-medium text-slate-400">{{ __('📸 現場照片') }} <span class="text-slate-600">（{{ __('選填；災時拍照請以自身安全為優先') }}）</span></label>
                            {{-- 縮圖預覽 --}}
                            <div x-show="preview" x-cloak class="mt-2 flex items-center gap-3">
                                <img :src="preview" alt="預覽" class="h-20 w-20 rounded-lg object-cover ring-1 ring-white/20">
                                <button type="button"
                                        @click="preview = null"
                                        class="text-xs text-slate-500 hover:text-slate-300">{{ __('✕ 重選') }}</button>
                            </div>
                            <input type="file" accept="image/*"
                                   wire:model="photo"
                                   x-on:change="setPreview($el.files); window.onPhotoChange($el, $wire)"
                                   class="mt-2 w-full rounded-lg border border-white/10 bg-black/30 p-2 text-sm">
                            @error('photo') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-400">{{ __('📍 災情位置') }} <span class="text-slate-600">（{{ __('移動地圖即可調整') }}）</span></label>
                            <div wire:ignore
                                 x-data
                                 x-init="initPinMap($el, $wire)"
                                 class="mt-2 h-48 w-full overflow-hidden rounded-lg border border-white/10 bg-black/40"></div>
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
                                      placeholder="{{ __('例如：水淹到機車排氣管、整條巷子都停電…') }}"
                                      class="mt-2 w-full rounded-lg border border-white/10 bg-black/30 p-2 text-sm placeholder:text-slate-600"></textarea>
                            @error('description') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit"
                                wire:loading.attr="disabled"
                                class="w-full rounded-xl bg-cyan-600 px-4 py-3 font-bold text-white shadow-[0_0_20px_rgba(8,145,178,0.5)] active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span wire:loading.remove>{{ __('送出回報') }}</span>
                            <span wire:loading>{{ __('⏳ 回報傳送中…') }}</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    @endif
</div>
