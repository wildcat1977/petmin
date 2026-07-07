{{-- 圖卡輪播 banner（spec §12.5）：自動輪播啟用中圖卡；點擊看大圖。無圖卡不渲染。 --}}
@if (count($this->promoCards))
<div class="pointer-events-auto" data-promo-banner
     x-data="{ idx: 0, lightbox: null, count: {{ count($this->promoCards) }},
               init() { if (this.count > 1) { setInterval(() => { this.idx = (this.idx + 1) % this.count }, 5000) } } }">
    <div class="relative overflow-hidden rounded-xl border border-white/10 bg-[#003a30]/95">
        @foreach ($this->promoCards as $i => $card)
            <button type="button" x-show="idx === {{ $i }}" x-cloak x-transition.opacity
                    @click="lightbox = @js($card['image_url'])"
                    class="block w-full">
                <img src="{{ $card['image_url'] }}" alt="{{ $card['title'] }}" loading="lazy"
                     class="aspect-[3/1] w-full object-cover">
            </button>
        @endforeach
        @if (count($this->promoCards) > 1)
            <div class="absolute inset-x-0 bottom-1 flex justify-center gap-1">
                @foreach ($this->promoCards as $i => $card)
                    <span class="h-1.5 w-1.5 rounded-full transition"
                          :class="idx === {{ $i }} ? 'bg-cyan-300' : 'bg-white/30'"></span>
                @endforeach
            </div>
        @endif
    </div>

    {{-- lightbox --}}
    <div x-show="lightbox" x-cloak @click="lightbox = null"
         class="fixed inset-0 z-[600] flex items-center justify-center bg-black/90 p-4">
        <img :src="lightbox" @click.stop class="max-h-[80dvh] max-w-full rounded-xl object-contain">
    </div>
</div>
@endif
