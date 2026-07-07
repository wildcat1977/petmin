{{-- 免責聲明（spec §11.3）：進站每 browser session 跳一次，已讀記 sessionStorage --}}
<div x-data="{ show: ! sessionStorage.getItem('hazdar:disclaimer:entry') }"
     x-show="show"
     x-cloak
     class="fixed inset-0 z-[3000] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm">
    <div class="w-full max-w-md rounded-3xl bg-[#161b22] p-6 text-slate-100 shadow-2xl ring-1 ring-white/10">
        <h2 class="text-lg font-bold">⚠️ {{ __('使用前請注意') }}</h2>
        <p class="mt-3 text-sm leading-relaxed text-slate-300">
            {{ __('本地圖彙整網友回報與氣象局雨量回報所得之災情資訊，僅供大眾參考，不代表市府公告，緊急事故請直接撥打119/1999，切勿僅依賴本平台等待救援。希望市民朋友保護自身安全，如非必要請勿出門。') }}
        </p>
        <button type="button"
                @click="sessionStorage.setItem('hazdar:disclaimer:entry', '1'); show = false"
                class="mt-5 w-full rounded-xl bg-cyan-600 px-4 py-3 font-semibold hover:bg-cyan-500">
            {{ __('我知道了') }}
        </button>
    </div>
</div>
