<?php

namespace App\Services\Site;

class RichTextRenderer
{
    /**
     * Render a TipTap doc structure to safe HTML.
     * Constrained subset: paragraphs, H2/H3, bold/italic/strikethrough,
     * lists (bullet/ordered), links (http/https only), blockquotes.
     */
    public function render(array $doc): string
    {
        if (($doc['type'] ?? null) !== 'doc') {
            return '';
        }

        return $this->renderNodes($doc['content'] ?? []);
    }

    /**
     * Shape-agnostic entry point for section body/answer values, which are
     * either TipTap docs (arrays) or plain strings. The section editors
     * flatten docs to strings joined with "\n\n" precisely so paragraph
     * breaks survive a <textarea> round-trip — so the string path must
     * honour that convention: blank lines separate <p> blocks, single
     * newlines become <br>. Rendering a string through bare e() collapses
     * the breaks into one blob paragraph (the "save loses formatting" bug).
     */
    public function renderValue(mixed $value): string
    {
        if (is_array($value)) {
            return $this->render($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        $paragraphs = preg_split('/(?:\r?\n){2,}/', trim(str_replace("\r\n", "\n", $value)));

        return implode('', array_map(function (string $para) {
            $safe = htmlspecialchars(trim($para), ENT_QUOTES, 'UTF-8');

            return '<p>'.str_replace("\n", '<br>', $safe).'</p>';
        }, array_filter($paragraphs, fn ($p) => trim($p) !== '')));
    }

    /**
     * Inverse of the flatten convention: lift a plain string (blank lines =
     * paragraph breaks, single newlines = hard breaks) into a TipTap doc.
     * Used to seed the WYSIWYG when a section still holds a legacy string
     * body, so editing always operates on — and saves back — a doc.
     *
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     */
    public function docFromPlainText(string $text): array
    {
        $paragraphs = preg_split('/(?:\r?\n){2,}/', trim(str_replace("\r\n", "\n", $text)));

        $content = [];
        foreach ($paragraphs as $para) {
            if (trim($para) === '') {
                continue;
            }
            $inline = [];
            foreach (explode("\n", trim($para)) as $i => $line) {
                if ($i > 0) {
                    $inline[] = ['type' => 'hardBreak'];
                }
                if ($line !== '') {
                    $inline[] = ['type' => 'text', 'text' => $line];
                }
            }
            $content[] = ['type' => 'paragraph', 'content' => $inline];
        }

        return ['type' => 'doc', 'content' => $content];
    }

    protected function renderNodes(array $nodes): string
    {
        return implode('', array_map(fn ($n) => $this->renderNode($n), $nodes));
    }

    protected function renderNode(array $node): string
    {
        return match ($node['type'] ?? '') {
            'paragraph' => '<p>'.$this->renderInline($node['content'] ?? []).'</p>',
            'heading' => $this->renderHeading($node),
            'bulletList' => '<ul>'.$this->renderNodes($node['content'] ?? []).'</ul>',
            'orderedList' => '<ol>'.$this->renderNodes($node['content'] ?? []).'</ol>',
            'listItem' => '<li>'.$this->renderNodes($node['content'] ?? []).'</li>',
            'blockquote' => '<blockquote>'.$this->renderNodes($node['content'] ?? []).'</blockquote>',
            'hardBreak' => '<br>',
            default => '',
        };
    }

    protected function renderHeading(array $node): string
    {
        $level = (int) ($node['attrs']['level'] ?? 2);
        if (! in_array($level, [2, 3], true)) {
            // Degrade to paragraph (H1, H4-H6 not allowed)
            return '<p>'.$this->renderInline($node['content'] ?? []).'</p>';
        }

        return "<h{$level}>".$this->renderInline($node['content'] ?? [])."</h{$level}>";
    }

    protected function renderInline(array $nodes): string
    {
        return implode('', array_map(function ($n) {
            if (($n['type'] ?? null) !== 'text') {
                return $this->renderNode($n);
            }

            return $this->wrapMarks(htmlspecialchars($n['text'] ?? '', ENT_QUOTES, 'UTF-8'), $n['marks'] ?? []);
        }, $nodes));
    }

    protected function wrapMarks(string $text, array $marks): string
    {
        $out = $text;
        foreach ($marks as $mark) {
            $out = match ($mark['type'] ?? '') {
                'bold' => "<strong>{$out}</strong>",
                'italic' => "<em>{$out}</em>",
                'strike' => "<s>{$out}</s>",
                'link' => $this->wrapLink($out, $mark['attrs']['href'] ?? null),
                default => $out,
            };
        }

        return $out;
    }

    /**
     * @param  mixed  $href  intentionally mixed — guards against non-string payloads
     *                        that slip past schema validation (belt-and-braces).
     */
    protected function wrapLink(string $text, mixed $href): string
    {
        if (! is_string($href) || ! preg_match('#^https?://#i', $href)) {
            return $text;
        }
        $safe = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');

        return "<a href=\"{$safe}\">{$text}</a>";
    }
}
