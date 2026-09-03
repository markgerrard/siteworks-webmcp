/**
 * TipTap-backed WYSIWYG for the page-manager flyout's section body.
 *
 * The body field is a TipTap doc end-to-end: seeded server-side into
 * `editBodyDoc` (legacy strings are lifted to docs), edited here, and
 * saved back verbatim — formatting survives the round-trip, unlike the
 * old plain <textarea> which demoted docs to strings.
 *
 * TipTap is dynamically imported so the agents bundle only pays for it
 * when a body field is actually opened. Extensions are constrained to
 * what RichTextRenderer whitelists: paragraphs, H2/H3, bold, italic,
 * strike, lists, blockquote, links (http/https), hard breaks.
 */
export default function richBodyEditor() {
    // The Editor instance deliberately lives in a closure, NOT on the Alpine
    // data object: Alpine wraps its data in a reactive Proxy, and ProseMirror
    // compares state/transaction identity internally — a proxied Editor
    // throws "RangeError: Applying a mismatched transaction" on any command.
    let editor = null;

    return {
        tick: 0,

        async init() {
            const [{ Editor }, { default: StarterKit }, { default: Link }] = await Promise.all([
                import('@tiptap/core'),
                import('@tiptap/starter-kit'),
                import('@tiptap/extension-link'),
            ]);

            // The element may already be gone if the user closed the flyout
            // before the dynamic import resolved.
            if (!this.$refs.host || !this.$refs.host.isConnected) {
                return;
            }

            editor = new Editor({
                element: this.$refs.host,
                extensions: [
                    StarterKit.configure({
                        heading: { levels: [2, 3] },
                        code: false,
                        codeBlock: false,
                        horizontalRule: false,
                        link: false, // configured separately below
                    }),
                    Link.configure({
                        openOnClick: false,
                        defaultProtocol: 'https',
                    }),
                ],
                content: this.$wire.get('editBodyDoc') ?? { type: 'doc', content: [] },
                editorProps: {
                    attributes: {
                        class: 'prose prose-sm dark:prose-invert max-w-none min-h-28 px-3 py-2 focus:outline-none',
                    },
                },
                onUpdate: ({ editor }) => {
                    // Defer: syncs on the next Livewire request (Save click).
                    this.$wire.set('editBodyDoc', editor.getJSON(), false);
                },
                onTransaction: () => {
                    // Reactivity nudge so toolbar active-states re-evaluate.
                    this.tick++;
                },
            });

            // Expose for tests/debugging — raw reference, never proxied.
            this.$refs.host._tiptapEditor = editor;
        },

        destroy() {
            editor?.destroy();
            editor = null;
        },

        run(command, attrs = null) {
            if (!editor) return;
            const chain = editor.chain().focus();
            ({
                bold: () => chain.toggleBold(),
                italic: () => chain.toggleItalic(),
                bulletList: () => chain.toggleBulletList(),
                orderedList: () => chain.toggleOrderedList(),
            })[command]?.().run();
        },

        setLink() {
            if (!editor) return;
            const existing = editor.getAttributes('link').href ?? '';
            const url = window.prompt('Link URL (https://…) — leave empty to remove', existing);
            if (url === null) return; // cancelled
            const chain = editor.chain().focus().extendMarkRange('link');
            if (url.trim() === '') {
                chain.unsetLink().run();
                return;
            }
            const href = /^https?:\/\//i.test(url) ? url : `https://${url}`;
            chain.setLink({ href }).run();
        },

        active(name, attrs = null) {
            // `tick` is referenced so Alpine re-runs this on each transaction.
            void this.tick;

            return editor ? editor.isActive(name, attrs ?? {}) : false;
        },
    };
}
