document.addEventListener('change', async (event) => {
    const input = event.target.closest?.('[data-portrait-upload]');
    if (! input?.files?.[0]) {
        return;
    }

    const formData = new FormData;
    formData.append('file', input.files[0]);
    input.disabled = true;

    try {
        const response = await fetch(input.dataset.portraitUpload, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: formData,
        });

        if (! response.ok) {
            const body = await response.json().catch(() => ({}));
            window.alert(body?.errors?.file?.[0] ?? 'Portrait upload failed.');
            return;
        }

        const media = await response.json();
        const componentRoot = input.closest('[wire\\:id]');
        const component = componentRoot ? window.Livewire?.find(componentRoot.getAttribute('wire:id')) : null;
        if (! component || ! Number.isInteger(media.id)) {
            window.alert('Portrait upload could not be attached.');
            return;
        }

        await component.set(input.dataset.portraitProperty, media.id);
    } finally {
        input.value = '';
        input.disabled = false;
    }
});
