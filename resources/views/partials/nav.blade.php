{{--
    次要頁面（profile/reports/news/stats/transparency/contact）沿用全站共用 head nav
    （v0.3 三次回饋：先前這裡是獨立一份「◎ Hazdar」文字版 header，與首頁的
    banner+跑馬燈版本不一致；改成單一來源，兩邊都用 partials.site-header）。
--}}
@include('partials.site-header')

{{-- 聯絡表單 Modal（由 open-contact-form 事件觸發）--}}
<livewire:contact-form />

{{-- 登入引導 Modal（由共用 head nav 的登入鈕觸發 open-auth-onboarding，spec §12.16/PM 回饋）--}}
<livewire:auth-onboarding />
