{{-- Ergebnis der Namensprüfung je Dienst (Alpine: badges(<Ausdruck>) aus comboCard) – grün = kennt den Namen so, gelb = ähnlich, durchgestrichen = nicht gefunden --}}
<template x-for="b in badges({{ $expr }})" :key="b.service">
    <a :href="b.url || null" target="_blank" rel="noopener" :title="b.title"
       class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
       :class="{ 'bg-emerald-500/15 text-emerald-400': b.state === 'ok', 'bg-amber-500/15 text-amber-300': b.state === 'similar',
                 'bg-zinc-800 text-zinc-600 line-through': b.state === 'missing', 'bg-red-500/15 text-red-300': b.state === 'error' }">
        <span x-text="b.label"></span><span x-show="b.type" class="font-normal opacity-80" x-text="b.type"></span>
    </a>
</template>
<span x-show="!checked({{ $expr }})" class="text-[11px] text-zinc-600">noch nicht geprüft</span>
<span x-show="namesakes({{ $expr }}) > 1" x-cloak class="rounded-full bg-amber-500/15 px-2 py-0.5 text-[11px] font-medium text-amber-300"
      x-text="'⚠ ' + namesakes({{ $expr }}) + ' Interpreten heißen so – Zahlen evtl. von anderen'"
      title="Spotify, Last.fm & Co. unterscheiden gleichnamige Künstler oft nicht – Hörer und Treffer können zu einem anderen gehören"></span>
