<div x-data="{
    reduceMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    go(direction) {
        const scroller = this.$refs.scroller;
        if (! scroller) { return; }
        const delta = Math.max(scroller.clientWidth * 0.8, 220) * direction;
        scroller.scrollBy({ left: delta, behavior: this.reduceMotion ? 'auto' : 'smooth' });
    }
}" class="relative">
    <button type="button" @click="go(-1)" aria-label="Previous {{ $carouselLabel }}" style="position: absolute; left: 0; top: 50%; z-index: 10; transform: translateY(-50%); padding: 0.5rem 0.75rem; background-color: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button);">‹</button>
    <div x-ref="scroller" tabindex="0" @keydown.arrow-left.prevent="go(-1)" @keydown.arrow-right.prevent="go(1)" style="display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 1rem; -webkit-overflow-scrolling: touch;">
        @foreach ($carouselItems as $carouselItem)
            <div style="flex: 0 0 min(80%, 18rem); scroll-snap-align: start; min-width: 0;">
                @include($carouselItemView, array_merge($carouselItemExtra ?? [], [$carouselItemVariable => $carouselItem]))
            </div>
        @endforeach
    </div>
    <button type="button" @click="go(1)" aria-label="Next {{ $carouselLabel }}" style="position: absolute; right: 0; top: 50%; z-index: 10; transform: translateY(-50%); padding: 0.5rem 0.75rem; background-color: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button);">›</button>
</div>
