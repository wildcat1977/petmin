{{-- 免責聲明：進站每 browser session 跳一次，已讀記 sessionStorage --}}
<div x-data="{ show: ! sessionStorage.getItem('petmin:disclaimer:entry') }"
     x-show="show"
     x-cloak
     class="fixed inset-0 z-[3000] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm">
    <div class="w-full max-w-md rounded-3xl bg-[#161b22] p-6 text-slate-100 shadow-2xl ring-1 ring-white/10">
        <h2 class="text-lg font-bold">⚠️ {{ __('使用前請注意') }}</h2>
        <p class="mt-3 text-sm leading-relaxed text-slate-300">
            {{ __('本地圖彙整環保局開放資料與市民回報，僅供大眾參考，不代表市府公告。清運需求請同時撥打 1999 或使用台北市民當家熱線。回報時請注意自身與毛孩安全。') }}
        </p>
        <button type="button"
                @click="sessionStorage.setItem('petmin:disclaimer:entry', '1'); show = false"
                class="mt-5 w-full rounded-xl bg-cyan-600 px-4 py-3 font-semibold hover:bg-cyan-500">
            {{ __('我知道了') }}
        </button>
    </div>
</div>
