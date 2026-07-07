<div class="mt-2 space-y-3">
    @foreach ($this->infoLinks as $section => $links)
        <div>
            <p class="text-[11px] font-semibold text-slate-400">{{ \App\Models\InfoLink::SECTION_LABELS[$section] ?? $section }}
                @if ($section === 'shelter')
                    <span class="ml-1 rounded bg-emerald-500/15 px-1.5 py-0.5 text-[10px] text-emerald-300">開設中 {{ $this->openShelterCount }}</span>
                @endif
            </p>
            @if (count($links))
                <ul class="mt-1 space-y-0.5">
                    @foreach ($links as $link)
                        <li class="flex items-baseline justify-between gap-2 text-xs">
                            <a href="{{ $link['url'] }}" target="_blank" rel="noopener" class="text-cyan-400 hover:text-cyan-300">{{ $link['label'] }} ↗</a>
                            @if ($link['status_text'])<span class="text-[10px] text-slate-500">{{ $link['status_text'] }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach
</div>
