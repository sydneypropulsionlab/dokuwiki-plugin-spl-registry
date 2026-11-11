<?php

namespace dokuwiki\plugin\splinductions;

/**
 * A wiki page split into its title, header table, intro and sections.
 *
 * Works on raw DokuWiki syntax as the SPL WHS pages are written: a level 1 title, a header
 * table of "^ Key | value |" rows, an intro paragraph, then level 2 sections with optional
 * level 3 sub-sections. Author notes in comments are removed before parsing.
 */
class WikiDoc
{
    public string $title = '';
    /** the title as written, before markup is removed */
    public string $titleRaw = '';
    /** @var array<string,string[]> normalised key => raw value cells (first row with that key) */
    public array $meta = [];
    /** @var array<int,array{key:string,label:string,value:string,raw:string}> every header row, in order */
    public array $rows = [];
    /** @var string[] header table lines that have no value cell */
    public array $badRows = [];
    public string $intro = '';
    /** the intro as written, before markup is removed */
    public string $introRaw = '';
    /** @var array<int,array{title:string,lines:string[],subs:array<int,array{title:string,lines:string[]}>}> */
    public array $sections = [];

    public function __construct(string $text)
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = self::stripComments($text);
        $pre = [];
        $sec = -1;
        $sub = -1;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*(={2,6})\s*(.+?)\s*={2,6}\s*$/', $line, $m)) {
                $level = 7 - strlen($m[1]);
                $title = WikiText::plain($m[2]);
                if ($level === 1) {
                    if ($this->title === '') {
                        $this->title = $title;
                        $this->titleRaw = trim($m[2]);
                    }
                    continue;
                }
                if ($level === 2) {
                    $this->sections[] = ['title' => $title, 'lines' => [], 'subs' => []];
                    $sec = count($this->sections) - 1;
                    $sub = -1;
                    continue;
                }
                if ($sec >= 0) {
                    $this->sections[$sec]['subs'][] = ['title' => $title, 'lines' => []];
                    $sub = count($this->sections[$sec]['subs']) - 1;
                    $this->sections[$sec]['lines'][] = $line;
                    continue;
                }
            }
            if ($sec < 0) {
                $pre[] = $line;
            } else {
                $this->sections[$sec]['lines'][] = $line;
                if ($sub >= 0) $this->sections[$sec]['subs'][$sub]['lines'][] = $line;
            }
        }

        $intro = [];
        foreach ($pre as $line) {
            $t = trim($line);
            if ($t === '') continue;
            if ($t[0] === '^' || $t[0] === '|') {
                $this->addMetaRow($t);
                continue;
            }
            // <Capitalised text> is a template placeholder, not a layout tag like <grid> or <col>
            if (self::isMarkupOnly($t) && !preg_match('/^<[A-Z][^<>]*>$/', $t)) continue;
            $intro[] = $t;
        }
        $this->introRaw = implode(' ', $intro);
        $this->intro = WikiText::plain($this->introRaw);
    }

    /** Remove /* author notes *\/ (commentsyntax plugin) and HTML-style comments. */
    public static function stripComments(string $text): string
    {
        $text = preg_replace('#/\*.*?\*/#s', '', $text);
        return preg_replace('#<!--.*?-->#s', '', $text);
    }

    /** Lines that are layout only: tags, images, tag lists, macros. */
    public static function isMarkupOnly(string $t): bool
    {
        if ($t === '') return true;
        if ($t[0] === '<') return true;
        if (preg_match('/^~~.*~~$/', $t)) return true;
        if (preg_match('/^(\{\{[^}]*\}\}\s*)+$/', $t)) return true;
        return false;
    }

    protected function addMetaRow(string $row): void
    {
        // keep the | inside [[link|label]] and {{image|title}} from splitting the row
        $saved = [];
        $masked = preg_replace_callback('/\[\[.*?\]\]|\{\{.*?\}\}/', function ($m) use (&$saved) {
            $saved[] = $m[0];
            return "\x01" . (count($saved) - 1) . "\x02";
        }, $row);
        $inner = preg_replace('/^[\^|]\s*/', '', rtrim($masked));
        $inner = preg_replace('/\s*[\^|]$/', '', $inner);
        $cells = array_map(fn($c) => preg_replace_callback('/\x01(\d+)\x02/', fn($m) => $saved[(int)$m[1]], trim($c)),
            preg_split('/[|^]/', $inner));
        $label = $cells[0] ?? '';
        if ($label === '' || $label === ':::') return;   // continuation (rowspan) rows
        if (count($cells) < 2) {
            $this->badRows[] = trim($row);
            return;
        }
        $key = self::norm($label);
        if ($key === '') return;
        $value = $cells[1];
        $this->rows[] = ['key' => $key, 'label' => rtrim(WikiText::plain($label), ': '), 'value' => $value, 'raw' => trim($row)];
        if (!isset($this->meta[$key])) {
            $rest = array_values(array_filter(array_slice($cells, 1), fn($c) => $c !== '' && $c !== ':::'));
            $this->meta[$key] = $value === '' ? array_merge([''], $rest) : $rest;
        }
    }

    /** First header value whose key starts with any of the given prefixes (raw markup kept). */
    public function metaRaw(array $prefixes): ?string
    {
        foreach ($prefixes as $p) {
            $p = self::norm($p);
            foreach ($this->meta as $key => $cells) {
                if ($key === $p || str_starts_with($key, $p)) return $cells[0];
            }
        }
        return null;
    }

    public function metaPlain(array $prefixes): string
    {
        $raw = $this->metaRaw($prefixes);
        return $raw === null ? '' : WikiText::plain($raw);
    }

    /** Lower case, punctuation removed: used for every name and prefix comparison. */
    public static function norm(string $s): string
    {
        $s = strtolower(WikiText::plain($s));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    public function section(string $prefix): ?array
    {
        $p = self::norm($prefix);
        if ($p === '') return null;
        foreach ($this->sections as $s) {
            if (str_starts_with(self::norm($s['title']), $p)) return $s;
        }
        // an anchor such as #emergency_shutdown
        foreach ($this->sections as $s) {
            if (str_starts_with(str_replace(' ', '', self::norm($s['title'])), str_replace(' ', '', $p))) return $s;
        }
        return null;
    }

    /**
     * Lines -> list of [kind, text, depth]. kind is bullet, num, para or heading.
     * Layout tags and table rows are skipped; paragraph lines are joined.
     */
    public static function blocks(array $lines): array
    {
        $out = [];
        $prevBlank = true;
        foreach ($lines as $raw) {
            $t = trim($raw);
            if ($t === '') {
                $prevBlank = true;
                continue;
            }
            if (preg_match('/^\s*={2,6}.*={2,6}\s*$/', $raw)) {
                $out[] = ['heading', WikiText::plain(trim($t, '= ')), 0];
                $prevBlank = true;
                continue;
            }
            if ($t[0] === '^' || $t[0] === '|' || self::isMarkupOnly($t)) {
                $prevBlank = true;
                continue;
            }
            if (preg_match('/^( {2,}|\t+)([*-])\s+(.*)$/', $raw, $m)) {
                $depth = max(1, intdiv(strlen(str_replace("\t", '  ', $m[1])), 2));
                $out[] = [$m[2] === '*' ? 'bullet' : 'num', $m[3], $depth];
                $prevBlank = false;
                continue;
            }
            $last = count($out) - 1;
            if (!$prevBlank && $last >= 0 && $out[$last][0] === 'para') {
                $out[$last][1] .= ' ' . $t;
            } else {
                $out[] = ['para', $t, 0];
            }
            $prevBlank = false;
        }
        return $out;
    }
}
