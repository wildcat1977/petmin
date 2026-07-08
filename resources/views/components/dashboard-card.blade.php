{{-- 可收合浮動卡片（spec §11.1）：狀態記 localStorage，行動版預設收合，桌機預設展開。
     x-collapse 外掛未安裝（無 @alpinejs/collapse 依賴）：改用 x-show + x-transition。 --}}
@props(['cardKey', 'title'])

<div class="pointer-events-auto overflow-hidden rounded-xl border border-white/10 bg-radar-card/95 backdrop-blur"
     x-data="{
         open: (() => {
             const v = localStorage.getItem('hazdar:card:{{ $cardKey }}');
             if (v !== null) return v === '1';
             return window.matchMedia('(min-width: 1024px)').matches; // 桌機預設開、行動預設收合
         })(),
         toggle() { this.open = !this.open; localStorage.setItem('hazdar:card:{{ $cardKey }}', this.open ? '1' : '0'); }
     }">
    <button type="button" @click="toggle()"
            class="flex w-full items-center justify-between px-3 py-2 text-[11px] font-semibold tracking-widest text-slate-400">
        <span>{{ $title }}</span>
        <span x-text="open ? '﹀' : '︿'" class="text-slate-500"></span>
    </button>
    <div x-show="open" x-cloak x-transition class="px-3 pb-3">
        {{ $slot }}
    </div>
</div>
