<?php

namespace dokuwiki\plugin\splinductions;

/**
 * An SWP or risk assessment page that induction references resolve against.
 *
 * Understands the SPL wiki layout:
 *  - SWP hazards: "**Hazard name** \\" lines (followed by //Category:// and the text) inside the
 *    Hazards section, or level 3 headings there;
 *  - SWP steps: the top-level numbered list ("  - ") in the Steps section;
 *  - RA risks: level 3 headings in the Risks section;
 *  - RA emergency controls: list items in the Emergency controls section.
 */
class Source
{
    public string $kind;
    public string $page;
    public int $rev;
    public string $version;
    public string $hash;
    public string $ref;
    public string $title;
    public WikiDoc $doc;

    public function __construct(string $kind, string $page, array $rev)
    {
        $this->kind = $kind;
        $this->page = $page;
        $this->rev = (int)($rev['rev'] ?? 0);
        $this->version = (string)($rev['version'] ?? '');
        $text = str_replace("\r\n", "\n", (string)$rev['text']);
        $this->hash = substr(hash('sha256', $text), 0, 12);
        $this->doc = new WikiDoc($text);
        $this->title = $this->doc->title !== '' ? $this->doc->title : $page;
        $ref = $this->doc->metaPlain(['swp reference number', 'reference', 'ref']);
        $this->ref = $ref !== '' ? $ref : $this->title;
    }

    public function summary(): array
    {
        return ['kind' => $this->kind, 'page' => $this->page, 'file' => $this->page, 'ref' => $this->ref,
            'title' => $this->title, 'version' => $this->version, 'hash' => $this->hash];
    }

    /** @return array<int,array{0:string,1:string}> [name, text] */
    public function hazards(): array
    {
        $s = $this->doc->section('hazard');
        if (!$s) return [];
        $entries = [];
        $open = -1;
        foreach ($s['lines'] as $line) {
            $t = trim($line);
            if (preg_match('/^\*\*(.+?)\*\*\s*(\\\\\\\\)?\s*$/', $t, $m) && !str_ends_with(trim($m[1]), ':')) {
                $entries[] = [WikiText::plain($m[1]), []];
                $open = count($entries) - 1;
                continue;
            }
            if ($open < 0) continue;
            if ($t === '') {
                $entries[$open][1][] = '';
                continue;
            }
            if (str_starts_with($t, '**') || $t[0] === '<' || preg_match('/^={2,}/', $t)) {
                $open = -1;
                continue;
            }
            if (preg_match('#^//\s*category\s*:?\s*//#i', $t)) continue;
            $entries[$open][1][] = $line;
        }
        $out = [];
        foreach ($entries as [$name, $lines]) $out[] = [$name, self::joinText($lines)];
        foreach ($s['subs'] as $sub) {
            $lines = array_filter($sub['lines'], fn($l) => !preg_match('/^\s*(category\s*:|\/\/\s*category)/i', trim($l)));
            $out[] = [$sub['title'], self::joinText($lines)];
        }
        return $out;
    }

    public function steps(): array
    {
        $s = $this->doc->section('step');
        if (!$s) return [];
        $steps = [];
        foreach (WikiDoc::blocks($s['lines']) as [$kind, $text, $depth]) {
            if ($kind === 'num' && $depth === 1) $steps[] = WikiText::plain($text);
        }
        return $steps;
    }

    protected static function joinText(array $lines): string
    {
        $parts = [];
        foreach (WikiDoc::blocks($lines) as [$kind, $text]) {
            if ($kind !== 'heading') $parts[] = WikiText::plain($text);
        }
        return trim(implode(' ', array_filter($parts, 'strlen')));
    }

    protected static function listText(array $lines): string
    {
        $items = [];
        foreach (WikiDoc::blocks($lines) as [$kind, $text]) {
            if ($kind === 'heading') continue;
            $t = WikiText::plain($text);
            if ($t !== '') $items[] = '- ' . $t;
        }
        return implode("\n", $items);
    }

    /**
     * Resolve a reference. $what: intro, section, hazard, step, risk, emergency.
     * @return array{label:string,text:string}|null
     */
    public function resolve(string $what, string $arg): ?array
    {
        $what = strtolower($what);
        $arg = trim($arg);
        $p = WikiDoc::norm($arg);
        switch ($what) {
            case 'intro':
            case 'introduction':
                if ($this->doc->intro === '') return null;
                return ['label' => "{$this->ref}: introduction", 'text' => $this->doc->intro];
            case 'section':
                $s = $this->doc->section($arg);
                if (!$s) return null;
                return ['label' => "{$this->ref}: {$s['title']}", 'text' => self::listText($s['lines'])];
            case 'hazard':
                if ($p === '') return null;
                foreach ($this->hazards() as [$name, $text]) {
                    if (str_starts_with(WikiDoc::norm($name), $p)) {
                        return ['label' => "{$this->ref}: hazard - $name", 'text' => $text];
                    }
                }
                return null;
            case 'step':
                if (!preg_match('/^\d+$/', $arg)) return null;
                $steps = $this->steps();
                $n = (int)$arg;
                if ($n < 1 || $n > count($steps)) return null;
                return ['label' => "{$this->ref}: step $n", 'text' => $steps[$n - 1]];
            case 'risk':
                $s = $this->doc->section('risk');
                if (!$s || $p === '') return null;
                foreach ($s['subs'] as $sub) {
                    if (str_starts_with(WikiDoc::norm($sub['title']), $p)) {
                        $lines = array_slice($sub['lines'], 1); // drop the heading line
                        return ['label' => "{$this->ref}: risk - {$sub['title']}", 'text' => self::listText($lines)];
                    }
                }
                return null;
            case 'emergency':
                $s = $this->doc->section('emergency');
                if (!$s || $p === '') return null;
                foreach (WikiDoc::blocks($s['lines']) as [$kind, $text]) {
                    $t = WikiText::plain($text);
                    if ($kind !== 'heading' && str_starts_with(WikiDoc::norm($t), $p)) {
                        return ['label' => "{$this->ref}: emergency controls", 'text' => $t];
                    }
                }
                return null;
        }
        return null;
    }
}
