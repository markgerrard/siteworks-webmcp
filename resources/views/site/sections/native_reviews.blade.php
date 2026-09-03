@if (config('site.native_reviews_enabled') && $site->native_reviews_enabled)
    @php
        $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
            if (! $emitMarkers) {
                return '';
            }
            $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
            $sectionType = $section['type'] ?? '';

            return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
        };

        $heading = $section['title'] ?? 'Leave Us a Review';
        $maxItems = (int) ($section['max_items'] ?? 9);
        $reviews = $site->nativeReviews()->approved()->latest()->limit($maxItems)->get();
    @endphp

    <section class="site-section-spacing" style="background-color: var(--color-surface);">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            <h2 class="text-3xl md:text-5xl font-extrabold tracking-tight mb-12 text-center"
                style="color: var(--color-text);
                       font-family: var(--font-display);
                       letter-spacing: var(--heading-letter-spacing);"
                {!! $editor('title', 'plain') !!}>
                {{ $heading }}
            </h2>

            @if ($reviews->isNotEmpty())
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-14">
                    @foreach ($reviews as $review)
                        <figure class="p-7 rounded-lg h-full"
                                style="background-color: var(--color-surface-alt); border: 1px solid var(--color-border);">
                            <div class="mb-3" aria-label="{{ $review->rating }} out of 5 stars">
                                @for ($i = 1; $i <= 5; $i++)
                                    <span aria-hidden="true" style="color: {{ $i <= $review->rating ? '#f5a623' : 'var(--color-border)' }};">&#9733;</span>
                                @endfor
                            </div>
                            <blockquote class="text-base leading-relaxed mb-4" style="color: var(--color-text-muted-on-alt, var(--color-text-muted));">{{ $review->text }}</blockquote>
                            <figcaption class="font-bold" style="color: var(--color-text-on-alt, var(--color-text));">{{ $review->author_name }}</figcaption>
                        </figure>
                    @endforeach
                </div>
            @endif

            <div class="max-w-xl mx-auto"
                 x-data="{ submitted: false, sending: false, error: '', form: { author_name: '', rating: 5, text: '', website: '' },
                           async submit() {
                               this.sending = true; this.error = '';
                               try {
                                   const res = await fetch('/reviews', {
                                       method: 'POST',
                                       headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                                       body: JSON.stringify(this.form),
                                   });
                                   if (!res.ok) throw new Error('bad response');
                                   this.submitted = true;
                               } catch (e) {
                                   this.error = 'Sorry, something went wrong — please try again.';
                               } finally { this.sending = false; }
                           } }">
                <form x-show="!submitted" @submit.prevent="submit()" class="space-y-5">
                    <div>
                        <label class="block text-sm font-bold mb-2" style="color: var(--color-text);">Your name *</label>
                        <input type="text" required maxlength="80" x-model="form.author_name"
                               class="w-full px-4 py-3 rounded-lg"
                               style="background-color: var(--color-surface-alt); border: 1px solid var(--color-border); color: var(--color-text);">
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-2" style="color: var(--color-text);">Rating *</label>
                        <select x-model.number="form.rating" class="w-full px-4 py-3 rounded-lg"
                                style="background-color: var(--color-surface-alt); border: 1px solid var(--color-border); color: var(--color-text);">
                            @foreach ([5, 4, 3, 2, 1] as $stars)
                                <option value="{{ $stars }}">{{ str_repeat('★', $stars) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-2" style="color: var(--color-text);">Your review *</label>
                        <textarea required maxlength="2000" rows="5" x-model="form.text"
                                  class="w-full px-4 py-3 rounded-lg"
                                  style="background-color: var(--color-surface-alt); border: 1px solid var(--color-border); color: var(--color-text);"></textarea>
                    </div>
                    {{-- Honeypot: hidden from humans; bots that fill it get a fake success. --}}
                    <input type="text" x-model="form.website" name="website" tabindex="-1" autocomplete="off"
                           style="position: absolute; left: -9999px;" aria-hidden="true">
                    <p x-show="error" x-text="error" class="text-sm" style="color: #dc2626;"></p>
                    <button type="submit" x-bind:disabled="sending"
                            class="w-full px-6 py-4 font-bold rounded-lg"
                            style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">
                        <span x-show="!sending">Submit review</span>
                        <span x-show="sending">Sending…</span>
                    </button>
                </form>
                <p x-show="submitted" x-cloak class="text-center text-lg font-bold py-8" style="color: var(--color-text);">
                    Thank you — your review has been received and will appear once approved.
                </p>
            </div>
        </div>
    </section>
@endif
