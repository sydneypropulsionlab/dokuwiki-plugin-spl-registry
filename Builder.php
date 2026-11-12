<?php

namespace dokuwiki\plugin\splregistry;

/**
 * Builds the registry bundle from record pages: inductions and checklists. Format 2
 * (registry.json) carries both; format 1 (inductions.json, what older versions of the app read)
 * carries the inductions only.
 *
 * Page text comes from a reader callback so the builder can be pointed at approved revisions
 * (publishing) or at an unsaved draft (checking a page on save):
 *     $read(string $pageId): ?array{text:string, rev:int, version:string, approved?:string, approved_by?:string}
 * and wiki links are resolved relative to the page they appear on with
 *     $resolve(string $contextPageId, string $target): string
 */
class Builder
{
    /** "[kind args] text": the bracket can't be the start of a [[link]] */
    public const ITEM_RE = '/^\[([^\[\]]*)\](?!\])\s*(.*)$/';
    /** what can be written in the brackets => item kind */
    public const KINDS = ['' => 'check', 'x' => 'check', 'check' => 'check', 'demo' => 'demo', 'ack' => 'ack',
        'pass' => 'pass', 'passfail' => 'pass', 'pass-fail' => 'pass', 'num' => 'num', 'number' => 'num',
        'select' => 'select', 'choice' => 'select', 'text' => 'text', 'rich' => 'rich', 'richtext' => 'rich',
        'photo' => 'photo'];
    /** item kinds each record type can use (plus info) */
    public const TYPE_KINDS = [
        'induction' => ['check', 'demo', 'ack'],
        'checklist' => ['check', 'ack', 'pass', 'num', 'select', 'text', 'rich', 'photo'],
    ];
    public const TYPES = ['induction', 'checklist'];
    /** the Type values used before checklists existed: an induction of that category */
    public const LEGACY_TYPES = ['equipment', 'space'];
    /** the keywords Outcome took before it held the words themselves */
    public const OLD_OUTCOMES = ['go-nogo' => 'Go / No-go', 'go-no-go' => 'Go / No-go', 'pass-fail' => 'Pass / Fail'];
    /** {option} after an item's text => [field, value] */
    public const OPTIONS = ['photo' => ['photo', 'optional'], 'photo!' => ['photo', 'required'],
        'comment' => ['comment', 'optional'], 'comment on fail' => ['comment', 'on_fail'],
        'optional' => ['optional', true]];
    public const OPTION_RE = '/(?<![{\w])\{\s*([a-z][a-z !]*?)\s*\}(?!\})/i';
    public const REF_RE = '/^\s*(?:(?:swp|ra)\s*[:\-]\s*)?(hazard|step|section|intro|introduction|risk|emergency)\b\s*[:\-]?\s*(.*)$/i';

    /** @var callable */
    protected $read;
    /** @var callable */
    protected $resolve;
    /** @var callable|null reads SWP/RA pages; defaults to $read */
    protected $readSource;
    /** @var string[] tags that mark a page as a record */
    protected array $tags;
    protected array $swpNs;
    protected array $raNs;

    public array $errors = [];
    public array $warnings = [];
    /** @var array<string,string> pages left out of the bundle => reason */
    public array $skipped = [];
    /** @var array<string,array> page id => induction built from it */
    public array $byPage = [];
    /** @var array<string,string> page => induction id already published (used for held pages) */
    public array $knownIds = [];
    /** @var string[] pages whose approved revision could not be built (they keep their last published version) */
    public array $failed = [];

    /** @var array<string,Source|false> */
    protected array $sources = [];
    /** @var array<string,array|null> */
    protected array $texts = [];

    public function __construct(callable $read, callable $resolve, array $tags = ['record'],
                                array $swpNs = ['whs:swp'], array $raNs = ['whs:ra'], ?callable $readSource = null)
    {
        $this->read = $read;
        $this->readSource = $readSource;
        $this->resolve = $resolve;
        $this->tags = array_values(array_filter(array_map('strtolower', $tags), 'strlen'));
        $this->swpNs = $swpNs;
        $this->raNs = $raNs;
    }

    protected function readPage(string $id): ?array
    {
        if (!array_key_exists($id, $this->texts)) {
            $r = ($this->read)($id);
            $this->texts[$id] = ($r && trim((string)$r['text']) !== '') ? $r : null;
        }
        return $this->texts[$id];
    }

    /** True when the text carries a record tag. */
    public function isRecord(string $text): bool
    {
        return (bool)array_intersect($this->tags, WikiText::tags($text));
    }

    protected function inNs(string $id, array $namespaces): bool
    {
        foreach ($namespaces as $ns) {
            $ns = trim($ns, ': ');
            if ($ns !== '' && str_starts_with($id, $ns . ':')) return true;
        }
        return false;
    }

    /**
     * An SWP or RA page. These only supply the text attached to checklist items, so a missing
     * page is a warning, never a reason to hold an induction back.
     */
    protected function source(string $id, string $kind, string $where): ?Source
    {
        if (!array_key_exists($id, $this->sources)) {
            $r = $this->readSource ? ($this->readSource)($id) : $this->readPage($id);
            if ($r && trim((string)$r['text']) === '') $r = null;
            $this->sources[$id] = $r ? new Source($kind, $id, $r) : false;
        }
        if (!$this->sources[$id]) {
            $this->warnings[] = "$where: [[$id]] does not exist, so its items have no SWP/RA text attached";
        }
        return $this->sources[$id] ?: null;
    }

    /** Page ids linked in a header cell, plus any plain text left over. */
    protected function cellLinks(string $page, ?string $raw): array
    {
        $ids = [];
        $external = false;
        if ($raw === null) return [[], '', false];
        $rest = preg_replace_callback(WikiText::LINK_RE, function ($m) use ($page, &$ids, &$external) {
            $l = WikiText::link($m[1]);
            if ($l['external']) $external = true;
            elseif ($l['target'] !== '') $ids[] = ($this->resolve)($page, $l['target']);
            return ' ';
        }, $raw);
        return [$ids, WikiText::plain($rest), $external];
    }

    protected static function listCell(string $plain): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\s*[,;]\s*|\s{2,}/', $plain)), 'strlen'));
    }

    /** The induction id written in a page's header table (read without building it). */
    protected function inductionIdOf(string $page): ?string
    {
        $id = $this->byPage[$page]['id'] ?? '';
        if ($id === '' && ($r = $this->readPage($page))) {
            $id = (new WikiDoc($r['text']))->metaPlain(['induction id', 'record id', 'checklist id', 'id']);
        }
        if ($id === '') $id = $this->knownIds[$page] ?? '';
        return $id !== '' ? $id : null;
    }

    /**
     * Build one record (an induction or a checklist) from a page.
     * @return array|null null when the page is not a record (no tag) or cannot be read
     */
    public function buildRecord(string $page): ?array
    {
        $r = $this->readPage($page);
        if (!$r) {
            $this->skipped[$page] = 'no approved revision';
            return null;
        }
        if (!$this->isRecord($r['text'])) {
            $this->skipped[$page] = 'the approved revision is not tagged ' . implode(' or ', $this->tags);
            return null;
        }
        $where = $page;
        $doc = new WikiDoc($r['text']);
        $errorsBefore = count($this->errors);

        $h = $this->header($doc, $page);
        $recordType = $h['record_type'] ?? '';
        $title = $doc->title;
        if (preg_match(self::PLACEHOLDER_RE, $doc->titleRaw, $pm)) {
            $this->errors[] = "$where: the title still has the template placeholder {$pm[0]}; fill it in";
        } elseif ($title === '') {
            $this->errors[] = "$where: missing the ====== title ======";
        }
        if (preg_match(self::PLACEHOLDER_RE, $doc->introRaw, $pm)) {
            $this->errors[] = "$where: the description under the header still has the template placeholder "
                . self::short($pm[0]) . '; replace it with one or two sentences';
        }
        $headSources = $h['sources'];
        $sources = [];
        foreach ($headSources as $id => $kind) {
            $src = $this->source($id, $kind, $where);
            if ($src) $sources[$id] = $src;
        }

        // checklist sections
        $sections = [];
        $canFail = false;
        foreach ($doc->sections as $si => $s) {
            $items = [];
            foreach ($s['lines'] as $line) {
                $t = trim($line);
                if ($t === '' || $t[0] === '^' || $t[0] === '|' || WikiDoc::isMarkupOnly($t)) continue;
                if (preg_match('/^\s*={2,6}.*={2,6}\s*$/', $line)) continue;
                $extra = [];
                if (preg_match('/^( {2,}|\t+)[*-]\s+(.*)$/', $line, $m)) {
                    $content = $m[2];
                    $kind = 'info';
                    if (preg_match(self::ITEM_RE, $content, $im)) {
                        $parsed = $this->parseItem($where, $s['title'], $recordType, trim($im[1]), $im[2]);
                        if ($parsed) [$kind, $content, $extra] = $parsed;
                    }
                } else {
                    $kind = 'info';
                    $content = $t;
                    // an item that is not indented two spaces isn't a list item on the wiki
                    if (preg_match('/^[*-]\s*\[([^\[\]]*)\](?!\])/', $t, $bm)
                        && isset(self::KINDS[strtolower(preg_split('/\s+/', trim($bm[1]))[0])])) {
                        $this->errors[] = "$where: \"{$s['title']}\" has a checklist item that is not indented "
                            . 'two spaces, so the wiki does not show it as a list item: ' . self::short($t);
                    } elseif (preg_match('/^\s?[*-]\s+\S/', $line) && !preg_match('/^-{4,}/', $t)) {
                        $this->warnings[] = "$where: \"{$s['title']}\" has a line starting \"" . $t[0]
                            . '" that is not indented two spaces, so it is treated as information: ' . self::short($t);
                    }
                }
                [$text, $refs] = $this->itemRefs($page, $content, $headSources, $sources);
                if ($text === '' && !$refs) continue;
                if ($kind !== 'info' && $text === '') {
                    $this->errors[] = "$where: \"{$s['title']}\" has a [$kind] item with no text";
                }
                $item = ['id' => ($si + 1) . '.' . (count($items) + 1), 'kind' => $kind, 'text' => $text,
                    'refs' => $refs];
                if (!empty($extra['_can_fail'])) $canFail = true;
                unset($extra['_can_fail']);
                $items[] = $item + $extra;
            }
            if ($items) $sections[] = ['title' => $s['title'], 'items' => $items];
        }
        $hasTicks = false;
        foreach ($sections as $s) foreach ($s['items'] as $it) if ($it['kind'] !== 'info') $hasTicks = true;
        if (!$hasTicks) {
            $this->errors[] = "$where: no checklist items (\"  * [ ] ...\", \"  * [pass] ...\" and so on, "
                . 'indented two spaces)';
        }

        $common = [
            'id' => $h['id'],
            'title' => $title,
            'location' => $h['location'],
            'description' => $doc->intro,
            'refresh_months' => $h['refresh'],
            'self_service' => $h['self'],
            'requires' => array_values(array_unique($h['requires'])),
            'sources' => array_values(array_map(fn(Source $s) => $s->summary(), $sources)),
            'sections' => $sections,
            'page' => $page,
        ];
        // only when yes, so a page without the row keeps the version it had before the row existed
        if ($h['signature']) $common['signature_required'] = true;
        if ($recordType === 'checklist') {
            $outcome = $h['outcome'] ?? ($canFail ? ['Pass', 'Fail'] : ['Complete']);
            if (count($outcome) === 2 && !$canFail && $hasTicks) {
                $this->warnings[] = "$where: Outcome is " . implode(' / ', $outcome) . " but no item can fail ([pass], [num] with limits, "
                    . 'or [select] with a choice ending in !), so the result is always ' . $outcome[0];
            }
            $rec = $common + ['record_type' => 'checklist', 'category' => $h['category'], 'outcome' => $outcome];
            $rec['version'] = substr(hash('sha256', self::canonical($rec)), 0, 12);
        } else {
            // the fields (and so the version) of an induction are exactly what they were before
            // checklists existed, so a page that hasn't changed keeps its version
            $rec = $common + [
                'type' => $h['category'] !== '' ? $h['category'] : 'induction',
                'grants_access' => array_column($h['resources'], 'name'),
                'resources' => $h['resources'],
            ];
            $rec['version'] = substr(hash('sha256', self::canonical($rec)), 0, 12);
            $rec['record_type'] = 'induction';
            $rec['category'] = $h['category'];
        }
        $rec['wiki'] = ['page' => $page, 'rev' => (int)$r['rev'], 'version' => (string)($r['version'] ?? ''),
            'approved' => $r['approved'] ?? null, 'approved_by' => $r['approved_by'] ?? null];
        $rec['_ok'] = count($this->errors) === $errorsBefore;
        $this->byPage[$page] = $rec;
        return $rec;
    }

    /**
     * Read "[kind args] text {options}". Returns [kind, text, extra fields] or null when the
     * bracket isn't an item kind (then the line is information and an error says why).
     */
    protected function parseItem(string $where, string $section, string $recordType, string $bracket,
                                 string $content): ?array
    {
        $err = fn(string $m) => $this->errors[] = "$where: \"$section\": $m: " . self::short("[$bracket] $content");
        $warn = fn(string $m) => $this->warnings[] = "$where: \"$section\": $m: " . self::short("[$bracket] $content");
        $parts = preg_split('/\s+/', $bracket, 2);
        $word = strtolower($parts[0] ?? '');
        $args = trim($parts[1] ?? '');
        $kind = self::KINDS[$word] ?? null;
        if ($kind === null) {
            if (mb_strlen($bracket) <= 20) {
                $err("[$bracket] isn't an item kind; use [ ], [demo] or [ack] in an induction, or [ ], [ack], "
                    . '[pass], [num], [select], [text], [rich] or [photo] in a checklist');
            }
            return null;
        }
        // with no valid Type yet, any kind is accepted (the Type row has its own error)
        $allowed = self::TYPE_KINDS[$recordType] ?? array_merge(...array_values(self::TYPE_KINDS));
        if (!in_array($kind, $allowed, true)) {
            if ($recordType === 'induction') {
                $err("[$word] is for checklists; an induction uses [ ] (covered), [demo] (demonstrated) or [ack] "
                    . '(acknowledged)');
            } else {
                $err("[$word] is for inductions (shown to an assessor); a checklist uses [ ] or [pass]");
            }
        }

        $extra = [];
        $canFail = false;
        if ($kind === 'num') {
            $num = '(-?\d+(?:\.\d+)?)';
            if (preg_match("/^$num\s*(?:-|–|—|to|\.\.)\s*$num\s*(.*)$/u", $args, $m)) {
                $extra += ['min' => (float)$m[1], 'max' => (float)$m[2]];
                $unit = trim($m[3]);
                if ($extra['min'] > $extra['max']) $err("the range {$m[1]}-{$m[2]} has the larger number first");
            } elseif (preg_match("/^(?:>=|≥|min)\s*$num\s*(.*)$/iu", $args, $m)) {
                $extra['min'] = (float)$m[1];
                $unit = trim($m[2]);
            } elseif (preg_match("/^(?:<=|≤|max)\s*$num\s*(.*)$/iu", $args, $m)) {
                $extra['max'] = (float)$m[1];
                $unit = trim($m[2]);
            } elseif (!preg_match('/\d/', $args)) {
                $unit = $args;
            } else {
                $err('write the limits as [num MIN-MAX unit], [num >= MIN unit], [num <= MAX unit] or [num unit]');
                $unit = '';
            }
            if ($unit !== '') $extra['unit'] = $unit;
            $canFail = isset($extra['min']) || isset($extra['max']);
        } elseif ($kind === 'select') {
            $choices = [];
            $fails = [];
            foreach (explode('|', $args) as $c) {
                $c = trim($c);
                $bad = str_ends_with($c, '!');
                $c = rtrim($c, '! ');
                if ($c === '') continue;
                if (in_array(strtolower($c), array_map('strtolower', $choices), true)) $err("\"$c\" is listed twice");
                $choices[] = $c;
                if ($bad) $fails[] = $c;
            }
            if (count($choices) < 2) $err('a [select] needs at least two choices separated by |, e.g. [select Yes|No]');
            $extra['choices'] = $choices;
            if ($fails) {
                $extra['fail_choices'] = $fails;
                $canFail = true;
            }
        } elseif ($args !== '' && !in_array($word, ['', 'x'], true)) {
            $err("[$word] doesn't take anything after it in the brackets");
        } elseif ($kind === 'pass') {
            $canFail = true;
        }

        // {options}
        $opts = [];
        $content = preg_replace_callback(self::OPTION_RE, function ($m) use (&$opts, $err) {
            $key = strtolower(preg_replace('/\s+/', ' ', trim($m[1])));
            if (!isset(self::OPTIONS[$key])) {
                $err("{{$m[1]}} isn't an option; use {photo}, {photo!}, {comment}, {comment on fail} or {optional}");
                return $m[0];
            }
            [$field, $value] = self::OPTIONS[$key];
            if (isset($opts[$field]) && $opts[$field] !== $value) {
                // {photo} and {photo!}: required wins; {comment} and {comment on fail}: always wins
                if ($field === 'photo') $value = 'required';
                if ($field === 'comment') $value = 'optional';
            }
            $opts[$field] = $value;
            return '';
        }, $content);
        if ($kind === 'photo' && isset($opts['photo'])) {
            $warn('a [photo] item is answered with a photo already, so {photo} does nothing');
            unset($opts['photo']);
        }
        if (($opts['comment'] ?? '') === 'on_fail' && !$canFail) {
            $warn('this item can\'t fail, so {comment on fail} never applies; use {comment} for a comment box');
        }
        return [$kind, trim($content), $extra + $opts + ($canFail ? ['_can_fail' => true] : [])];
    }

    /** Header rows the app uses: field => [label shown in messages, accepted row names]. */
    public const FIELDS = [
        'id' => ['ID', ['id', 'induction id', 'record id', 'checklist id']],
        'type' => ['Type', ['type']],
        'category' => ['Category', ['category']],
        'location' => ['Location', ['location']],
        'swp' => ['SWP', ['swp', 'swps']],
        'ra' => ['RA', ['ra', 'ras', 'risk assessment', 'risk assessments']],
        'grants' => ['Grants access', ['grants access']],
        'requires' => ['Requires', ['requires']],
        'refresh' => ['Refresh (months)', ['refresh', 'refresh months']],
        'self' => ['Self-service', ['self service', 'selfservice']],
        'outcome' => ['Outcome', ['outcome']],
        'signature' => ['Signature required', ['signature required', 'signature']],
    ];
    /** Rows every record must have; any other row can be left out (its default is used). */
    public const REQUIRED = ['id', 'type'];
    /** Rows that are shown on the page but not used by the app. */
    public const INFO_ROWS = ['approved by', 'version', 'issued', 'prepared by', 'next review', 'initial issue',
        'notes', 'supervisor', 'responsible supervisor', 'responsible supervisor s'];
    public const NONE = ['-', 'none', 'n/a', 'na'];
    /** template placeholders such as <ROOM> or <link the SWP, or -> */
    public const PLACEHOLDER_RE = '/<(?!\/?(?:badge|wrapper|del|sub|sup|code|nowiki)\b)[^<>]{2,}>/i';

    /** @var array<string,string[]> page => access ids in its published version */
    public array $knownResources = [];
    /** @var callable|null page id => bool */
    public $exists = null;

    protected function pageExists(string $id): bool
    {
        return $this->exists ? (bool)($this->exists)($id) : $this->readPage($id) !== null;
    }

    /**
     * Read and check every header row the app depends on. Anything that would give the app a
     * wrong or empty value is an error, so it is caught on preview before anyone approves the page.
     */
    protected function header(WikiDoc $doc, string $page): array
    {
        $where = $page;
        $err = function (string $m) use ($where) { $this->errors[] = "$where: $m"; };
        $warn = function (string $m) use ($where) { $this->warnings[] = "$where: $m"; };
        $out = ['id' => '', 'record_type' => 'induction', 'category' => '', 'location' => '', 'refresh' => 12,
            'self' => false, 'resources' => [], 'requires' => [], 'sources' => [], 'outcome' => null,
            'signature' => false];

        foreach ($doc->badRows as $raw) {
            $err("header row \"" . self::short($raw) . "\" has no value cell; rows look like ^ Label: | value |");
        }
        if (!$doc->rows) {
            $err('no header table found. It goes under the title, one row per field: ^ ID: | IND_... |');
            return $out;
        }

        // which row is which field
        $alias = [];
        foreach (self::FIELDS as $f => [, $names]) foreach ($names as $n) $alias[$n] = $f;
        $found = [];
        $unknown = [];
        foreach ($doc->rows as $row) {
            if (isset($alias[$row['key']])) {
                $found[$alias[$row['key']]][] = $row;
            } elseif (!in_array($row['key'], self::INFO_ROWS, true)) {
                $unknown[] = $row;
            }
        }
        $nearMiss = [];
        foreach ($unknown as $row) {
            $best = null;
            $bestD = 99;
            foreach ($alias as $n => $f) {
                $d = levenshtein($row['key'], $n);
                if ($d < $bestD) [$best, $bestD] = [$f, $d];
            }
            if ($best !== null && $bestD <= max(2, intdiv(strlen($row['key']), 4))) {
                $nearMiss[$best] = $row['label'];
            } else {
                $warn("header row \"{$row['label']}\" isn't used by the registry app (shown on the page only)");
            }
        }

        $value = [];
        foreach (self::FIELDS as $f => [$label]) {
            $rows = $found[$f] ?? [];
            if (!$rows) {
                $hint = isset($nearMiss[$f]) ? " (is \"{$nearMiss[$f]}\" meant to be \"$label\"?)" : '';
                if (in_array($f, self::REQUIRED, true)) $err("the header table has no \"$label\" row$hint");
                elseif ($hint !== '') $warn("the header table has no \"$label\" row$hint, so its default is used");
                continue;
            }
            if (count($rows) > 1) $err("the header table has " . count($rows) . " \"$label\" rows; keep one");
            $value[$f] = trim($rows[0]['value']);
            if (preg_match(self::PLACEHOLDER_RE, $value[$f], $pm)) {
                $err("\"$label\" still has the template placeholder {$pm[0]}; fill it in");
                $value[$f] = '';
                continue;
            }
            if ($value[$f] === '' && in_array($f, self::REQUIRED, true)) $err("\"$label\" is empty");
        }
        $plainOf = fn($f) => WikiText::plain($value[$f] ?? '');
        $isNone = fn($f) => in_array(strtolower($plainOf($f)), self::NONE, true);

        // ID
        $id = $plainOf('id');
        if ($id !== '') {
            if (!preg_match('/^[A-Za-z0-9_.-]+$/', $id)) {
                $err("ID \"$id\" may only contain letters, digits, _ . and - (no spaces)");
            }
            $old = $this->knownIds[$page] ?? '';
            if ($old !== '' && $old !== $id) {
                $err("ID changed from $old to $id. Records (and card readers) use the ID, so change it back; "
                    . 'for a different record, make a new page');
            }
        }
        $out['id'] = $id;

        // Type: induction or checklist. "equipment" and "space" (written before checklists existed)
        // mean an induction of that category.
        $type = strtolower($plainOf('type'));
        if (in_array($type, self::LEGACY_TYPES, true)) {
            $out['record_type'] = 'induction';
            $out['category'] = $type;
        } elseif (in_array($type, self::TYPES, true)) {
            $out['record_type'] = $type;
        } else {
            if ($type !== '') $err("Type must be \"induction\" or \"checklist\", not \"$type\"");
            $out['record_type'] = null;   // unknown: the page has an error anyway
        }
        $isInduction = $out['record_type'] !== 'checklist';

        // Category: any label; the app makes a filter for each one it sees
        if (isset($value['category']) && $value['category'] !== '' && !$isNone('category')) {
            $cat = $plainOf('category');
            if (mb_strlen($cat) > 40) $err('Category is a short label, e.g. preflight (40 characters at most)');
            $out['category'] = $cat;
        }
        $out['location'] = $isNone('location') ? '' : $plainOf('location');
        if (!$isInduction) $out['refresh'] = 0;

        // Outcome (checklists): "Good / Bad" (the result when nothing failed / when anything failed), or
        // one word for a checklist that only records that it was done
        if (isset($value['outcome']) && $value['outcome'] !== '' && !$isNone('outcome')) {
            $raw = $plainOf('outcome');
            $words = array_map('trim', explode('/', $raw));
            $old = self::OLD_OUTCOMES[strtolower($raw)] ?? null;
            if ($isInduction) {
                $warn('Outcome is only used by checklists; an induction ends in a sign-off');
            } elseif ($old !== null) {
                $err("Outcome now holds the words to show, not a keyword: write \"$old\" instead of \"$raw\"");
            } elseif (count($words) > 2 || in_array('', $words, true)) {
                $err("Outcome is two results separated by /, e.g. Go / No-go, or one word, e.g. Complete "
                    . "(found \"$raw\")");
            } elseif (max(array_map('mb_strlen', $words)) > 30) {
                $err('Each Outcome result is a word or two (30 characters at most)');
            } else {
                $out['outcome'] = $words;
            }
        }

        // Refresh (months)
        $r = $plainOf('refresh');
        if (in_array(strtolower($r), self::NONE, true)) $r = '0';
        if ($r !== '') {
            if (preg_match('/^(\d+)(\s*months?)?$/i', $r, $m)) {
                $out['refresh'] = (int)$m[1];
                if ($out['refresh'] > 120) $warn("Refresh is {$out['refresh']} months ($r); is that right?");
            } else {
                $err("Refresh (months) must be a whole number of months, e.g. 12 (0 = no expiry), not \"$r\"");
            }
        }

        // Self-service
        $sv = strtolower($plainOf('self'));
        if ($sv !== '') {
            if (in_array($sv, ['yes', 'y', 'true'], true)) $out['self'] = true;
            elseif (!in_array($sv, ['no', 'n', 'false'], true)) $err("Self-service must be yes or no, not \"$sv\"");
        }

        // Signature required: inductions, the inductee draws a signature as well as typing their name;
        // checklists, whoever submits it signs
        $sg = strtolower($plainOf('signature'));
        if ($sg !== '' && !$isNone('signature')) {
            if (in_array($sg, ['yes', 'y', 'true'], true)) $out['signature'] = true;
            elseif (!in_array($sg, ['no', 'n', 'false'], true)) $err("Signature required must be yes or no, not \"$sg\"");
        }

        // SWP and RA: links to the pages, or -
        foreach (['swp' => 'SWP', 'ra' => 'RA'] as $f => $label) {
            if (!isset($value[$f]) || $value[$f] === '' || $isNone($f)) continue;
            [$ids, $rest, $external] = $this->cellLinks($page, $value[$f]);
            $rest = trim(preg_replace('/[\s,;&]+|\band\b/', ' ', $rest));
            if ($external) $err("$label must link to a wiki page, not an outside address");
            if ($rest !== '' || !$ids) {
                $ex = $f === 'swp' ? '[[whs:swp:swp-002]]' : '[[whs:ra:creality-cr-10-printers]]';
                $err("$label should be a link to the $label page, e.g. $ex, or - for none (found \"" . self::short($plainOf($f)) . '")');
            }
            $ns = $f === 'swp' ? $this->swpNs : $this->raNs;
            foreach ($ids as $sid) {
                if (!$this->pageExists($sid)) {
                    $err("$label links to [[$sid]], which doesn't exist");
                    continue;
                }
                if (!$this->inNs($sid, $ns)) $warn("$label links to [[$sid]], which isn't in " . implode(', ', $ns));
                $out['sources'][$sid] = $f;
            }
        }

        // Grants access (inductions): "Name" or "id: Name", comma separated, or -
        if (!$isInduction && isset($value['grants']) && $value['grants'] !== '' && !$isNone('grants')) {
            $warn('Grants access is only used by inductions; a checklist opens no doors');
        } elseif (isset($value['grants']) && $value['grants'] !== '' && !$isNone('grants')) {
            $seenIds = [];
            $seenNames = [];
            foreach (self::listCell($plainOf('grants')) as $entry) {
                if (in_array(strtolower($entry), self::NONE, true)) {
                    $err('Grants access mixes - with entries; use one or the other');
                    continue;
                }
                if (preg_match('/^([^:]+):\s*(.*)$/', $entry, $m)) {
                    $given = trim($m[1]);
                    $rid = WikiText::slug($given);
                    $name = trim($m[2]);
                    if ($name === '') {
                        $err("Grants access entry \"$entry\" has an id but no name; write it as id: Name");
                        continue;
                    }
                    if ($rid !== '' && $rid !== $given) {
                        $warn("Grants access id \"$given\" is used as \"$rid\" (ids are lower case with - between words)");
                    }
                } else {
                    $rid = WikiText::slug($entry);
                    $name = $entry;
                }
                if ($rid === '') {
                    $err("Grants access entry \"$entry\" has no usable id (letters or digits needed)");
                    continue;
                }
                if (isset($seenIds[$rid])) $err("Grants access lists the id \"$rid\" twice");
                if (isset($seenNames[strtolower($name)])) $err("Grants access lists \"$name\" twice");
                $seenIds[$rid] = $seenNames[strtolower($name)] = true;
                $out['resources'][] = ['id' => $rid, 'name' => $name];
            }
        }
        if ($isInduction && $out['category'] === 'space' && !$out['resources'] && isset($value['grants'])) {
            $warn('this is a space induction but Grants access is empty, so it opens no doors');
        }
        $newIds = array_column($out['resources'], 'id');
        foreach ($this->knownResources[$page] ?? [] as $oldId) {
            if (!in_array($oldId, $newIds, true)) {
                $warn("Grants access no longer includes the id \"$oldId\" that is published now; card readers set up "
                    . 'with it will refuse people with this induction');
            }
        }

        // Requires: links to induction pages (or induction ids), or -
        if (isset($value['requires']) && $value['requires'] !== '' && !$isNone('requires')) {
            [$reqPages, $rest, $external] = $this->cellLinks($page, $value['requires']);
            if ($external) $err('Requires must link to induction pages, not an outside address');
            foreach ($reqPages as $rp) {
                if ($rp === $page) {
                    $err('Requires links to this page; a record cannot require itself');
                    continue;
                }
                if (!$this->pageExists($rp)) {
                    $err("Requires links to [[$rp]], which doesn't exist");
                    continue;
                }
                $rid = $this->inductionIdOf($rp);
                if ($rid === null) {
                    $warn("Requires links to [[$rp]], which has no approved ID yet");
                } else {
                    $out['requires'][] = $rid;
                }
            }
            foreach (self::listCell($rest) as $tok) {
                if (in_array(strtolower($tok), self::NONE, true)) {
                    $err('Requires mixes - with entries; use one or the other');
                } elseif (!preg_match('/^[A-Za-z0-9_.-]+$/', $tok)) {
                    $err("Requires should be links to induction pages, e.g. [[whs:inductions:ind-001]], or - "
                        . "(found \"$tok\")");
                } else {
                    $out['requires'][] = $tok;
                }
            }
        }
        return $out;
    }

    /** Pull SWP/RA references out of an item's text. Returns [plain text, refs]. */
    protected function itemRefs(string $page, string $content, array $headSources, array &$sources): array
    {
        $refs = [];
        $where = $page;
        $text = preg_replace_callback(WikiText::LINK_RE, function ($m) use ($page, $where, $headSources, &$sources, &$refs) {
            $l = WikiText::link($m[1]);
            if ($l['external']) return $m[0];
            $id = $l['target'] === '' ? $page : ($this->resolve)($page, $l['target']);
            $isSwp = $this->inNs($id, $this->swpNs) || ($headSources[$id] ?? '') === 'swp';
            $isRa = $this->inNs($id, $this->raNs) || ($headSources[$id] ?? '') === 'ra';
            if (!$isSwp && !$isRa) return $m[0];
            $label = $l['label'] ?? '';
            if (preg_match(self::REF_RE, $label, $rm)) {
                $what = strtolower($rm[1]);
                $arg = trim($rm[2]);
            } elseif ($label === '' && $l['anchor'] !== '') {
                $what = 'section';
                $arg = str_replace('_', ' ', $l['anchor']);
            } else {
                if (str_contains($label, ':')) {
                    $this->warnings[] = "$where: link \"$label\" to [[$id]] is not a reference (start the label with "
                        . "hazard:, step, section:, intro, risk: or emergency:)";
                }
                return $m[0];
            }
            $src = $sources[$id] ?? $this->source($id, $isRa ? 'ra' : 'swp', $where);
            if (!$src) return '';
            $sources[$id] = $src;
            $res = $src->resolve($what, $arg);
            if ($res === null) {
                $this->warnings[] = "$where: reference \"" . ($label !== '' ? $label : "#{$l['anchor']}")
                    . "\" does not match anything in [[$id]]" . ($src->version !== '' ? " (v{$src->version})" : '')
                    . "; the item is published without that text";
            } else {
                $refs[] = $res;
            }
            return "\x03";
        }, $content);
        // drop the reference links and any brackets left around them
        $text = preg_replace('/\(\s*(?:\x03[\s,;]*)+\)/', '', $text);
        $text = str_replace("\x03", '', $text);
        $text = WikiText::plain($text);
        $text = preg_replace('/\s+([,.;:)])/', '$1', $text);
        return [trim($text), $refs];
    }

    /**
     * Build the records from candidate pages (pages tagged as records). Pages with errors are left
     * out and listed in $failed; the caller decides what to do with them. Returns the good records.
     */
    public function build(array $pages): array
    {
        sort($pages);
        $records = [];
        foreach ($pages as $page) {
            $rec = $this->buildRecord($page);
            if ($rec) $records[] = $rec;
        }
        $this->crossCheck($records);
        $bad = [];
        foreach ($this->errors as $e) {
            if (preg_match('/^([^ ]+): /', $e, $m)) $bad[$m[1]] = true;
        }
        $ok = [];
        foreach ($records as $rec) {
            if (isset($bad[$rec['page']]) || !$rec['_ok']) {
                $this->failed[] = $rec['page'];
                $this->byPage[$rec['page']]['_ok'] = false;
                continue;
            }
            unset($rec['_ok']);
            $ok[] = $rec;
        }
        return $ok;
    }

    /** The registry bundle (format 2): inductions and checklists, sorted, with a content hash. */
    public static function bundle(array $records): array
    {
        usort($records, fn($a, $b) => strcmp($a['id'], $b['id']));
        $inductions = array_values(array_filter($records, fn($r) => ($r['record_type'] ?? 'induction') === 'induction'));
        $checklists = array_values(array_filter($records, fn($r) => ($r['record_type'] ?? '') === 'checklist'));
        $bundle = [
            'format' => 2,
            'generated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'generator' => 'splregistry DokuWiki plugin',
            'inductions' => $inductions,
            'checklists' => $checklists,
        ];
        $bundle['hash'] = self::hashOf(array_merge($inductions, $checklists));
        return $bundle;
    }

    /** The induction bundle (format 1) that versions of the app before 0.13 read: inductions only. */
    public static function legacyBundle(array $records): array
    {
        $inductions = [];
        foreach ($records as $r) {
            if (($r['record_type'] ?? 'induction') !== 'induction') continue;
            unset($r['record_type'], $r['category']);
            $inductions[] = $r;
        }
        usort($inductions, fn($a, $b) => strcmp($a['id'], $b['id']));
        $bundle = [
            'format' => 1,
            'generated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'generator' => 'splregistry DokuWiki plugin',
            'inductions' => $inductions,
        ];
        $bundle['hash'] = self::hashOf($inductions);
        return $bundle;
    }

    protected static function hashOf(array $records): string
    {
        return substr(hash('sha256', self::canonical(array_map(function ($r) {
            unset($r['wiki']);
            return $r;
        }, $records))), 0, 12);
    }

    public function crossCheck(array $records): void
    {
        $ids = [];
        $types = [];
        $known = array_flip($this->knownIds);
        foreach ($records as $rec) {
            if ($rec['id'] === '') continue;
            if (isset($ids[$rec['id']])) {
                $this->errors[] = "{$rec['page']}: ID {$rec['id']} is also used by {$ids[$rec['id']]}";
            } else {
                $ids[$rec['id']] = $rec['page'];
                $types[$rec['id']] = $rec['record_type'] ?? 'induction';
            }
        }
        $names = [];
        foreach ($records as $rec) {
            foreach ($rec['requires'] as $req) {
                if ($req === $rec['id']) $this->errors[] = "{$rec['page']}: a record cannot require itself";
                elseif (!isset($ids[$req]) && !isset($known[$req])) {
                    $this->warnings[] = "{$rec['page']}: requires $req, which is not published yet";
                } elseif (($types[$req] ?? 'induction') !== 'induction') {
                    $this->warnings[] = "{$rec['page']}: requires $req, which is a checklist; Requires is for "
                        . 'inductions a person must hold';
                }
            }
            foreach ($rec['resources'] ?? [] as $res) {
                if (!isset($names[$res['id']])) {
                    $names[$res['id']] = [$res['name'], $rec['id']];
                } elseif ($names[$res['id']][0] !== $res['name']) {
                    $this->errors[] = "{$rec['page']}: access id \"{$res['id']}\" is \"{$res['name']}\" here but "
                        . "\"{$names[$res['id']][0]}\" in {$names[$res['id']][1]}; give them different ids or the same name";
                }
            }
        }
        $isInd = fn($r) => ($r['record_type'] ?? 'induction') === 'induction';
        foreach ($records as $rec) {
            if (!$isInd($rec) || ($rec['category'] ?? '') !== 'equipment' || $rec['requires']) continue;
            $spaces = [];
            foreach ($records as $o) {
                if ($isInd($o) && ($o['category'] ?? '') === 'space' && $o['location'] === $rec['location']) {
                    $spaces[] = $o['id'];
                }
            }
            if ($spaces) {
                $this->warnings[] = "{$rec['page']}: {$rec['id']} is in {$rec['location']} but does not require "
                    . implode(', ', $spaces);
            }
        }
    }

    protected static function short(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return mb_strlen($s) > 70 ? mb_substr($s, 0, 67) . '...' : $s;
    }

    /** JSON with object keys sorted, for hashing (matches Python's json.dumps(sort_keys=True) closely enough). */
    public static function canonical($v): string
    {
        $sort = function ($x) use (&$sort) {
            if (!is_array($x)) return $x;
            if (array_is_list($x)) return array_map($sort, $x);
            ksort($x);
            return array_map($sort, $x);
        };
        return json_encode($sort($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
