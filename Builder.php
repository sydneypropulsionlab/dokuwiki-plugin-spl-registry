<?php

namespace dokuwiki\plugin\splinductions;

/**
 * Builds the induction bundle (format 1, the same JSON the SPL Inductions app imports) from
 * induction checklist pages.
 *
 * Page text comes from a reader callback so the builder can be pointed at approved revisions
 * (publishing) or at an unsaved draft (checking a page on save):
 *     $read(string $pageId): ?array{text:string, rev:int, version:string, approved?:string, approved_by?:string}
 * and wiki links are resolved relative to the page they appear on with
 *     $resolve(string $contextPageId, string $target): string
 */
class Builder
{
    public const ITEM_RE = '/^\[\s*(|x|demo|ack)\s*\]\s*(.*)$/i';  // [ ], [x], [demo], [ack]; spaces and case don't matter
    public const KINDS = ['' => 'check', ' ' => 'check', 'x' => 'check', 'demo' => 'demo', 'ack' => 'ack'];
    public const REF_RE = '/^\s*(?:(?:swp|ra)\s*[:\-]\s*)?(hazard|step|section|intro|introduction|risk|emergency)\b\s*[:\-]?\s*(.*)$/i';

    /** @var callable */
    protected $read;
    /** @var callable */
    protected $resolve;
    /** @var callable|null reads SWP/RA pages; defaults to $read */
    protected $readSource;
    protected string $tag;
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

    public function __construct(callable $read, callable $resolve, string $tag = 'induction',
                                array $swpNs = ['whs:swp'], array $raNs = ['whs:ra'], ?callable $readSource = null)
    {
        $this->read = $read;
        $this->readSource = $readSource;
        $this->resolve = $resolve;
        $this->tag = strtolower($tag);
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

    /** True when the text carries the induction tag. */
    public function isInduction(string $text): bool
    {
        return in_array($this->tag, WikiText::tags($text), true);
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
            $id = (new WikiDoc($r['text']))->metaPlain(['induction id', 'id']);
        }
        if ($id === '') $id = $this->knownIds[$page] ?? '';
        return $id !== '' ? $id : null;
    }

    /**
     * Build one induction from a page.
     * @return array|null null when the page is not an induction (no tag) or cannot be read
     */
    public function buildInduction(string $page): ?array
    {
        $r = $this->readPage($page);
        if (!$r) {
            $this->skipped[$page] = 'no approved revision';
            return null;
        }
        if (!$this->isInduction($r['text'])) {
            $this->skipped[$page] = "the approved revision is not tagged {$this->tag}";
            return null;
        }
        $where = $page;
        $doc = new WikiDoc($r['text']);
        $errorsBefore = count($this->errors);

        $h = $this->header($doc, $page);
        $indId = $h['id'];
        $title = $doc->title;
        if ($title === '') $this->errors[] = "$where: missing the ====== title ======";
        elseif (preg_match(self::PLACEHOLDER_RE, $doc->titleRaw, $pm)) {
            $this->errors[] = "$where: the title still has the template placeholder {$pm[0]}; fill it in";
        }
        if (preg_match(self::PLACEHOLDER_RE, $doc->introRaw, $pm)) {
            $this->errors[] = "$where: the description under the header still has the template placeholder "
                . self::short($pm[0]) . '; replace it with one or two sentences for the inductee';
        }
        $type = $h['type'];
        $location = $h['location'];
        $refresh = $h['refresh'];
        $self = $h['self'];
        $resources = $h['resources'];
        $requires = $h['requires'];
        $headSources = $h['sources'];
        $sources = [];
        foreach ($headSources as $id => $kind) {
            $src = $this->source($id, $kind, $where);
            if ($src) $sources[$id] = $src;
        }

        // checklist sections
        $sections = [];
        foreach ($doc->sections as $si => $s) {
            $items = [];
            foreach ($s['lines'] as $line) {
                $t = trim($line);
                if ($t === '' || $t[0] === '^' || $t[0] === '|' || WikiDoc::isMarkupOnly($t)) continue;
                if (preg_match('/^\s*={2,6}.*={2,6}\s*$/', $line)) continue;
                if (preg_match('/^( {2,}|\t+)[*-]\s+(.*)$/', $line, $m)) {
                    $content = $m[2];
                    if (preg_match(self::ITEM_RE, $content, $im)) {
                        $kind = self::KINDS[strtolower($im[1])];
                        $content = $im[2];
                    } else {
                        $kind = 'info';
                        // "[dmeo] ...", "[ack ]", "[]" : a mistyped item type, not information
                        if (preg_match('/^\[([^\]\[]{0,12})\](?!\])/', $content, $bm)) {
                            $this->errors[] = "$where: \"{$s['title']}\" has an item starting [{$bm[1]}]; "
                                . 'use [ ] (covered), [demo] (demonstrated) or [ack] (acknowledged): '
                                . self::short($content);
                        }
                    }
                } else {
                    $kind = 'info';
                    $content = $t;
                    // an item that is not indented two spaces isn't a list item on the wiki
                    if (preg_match('/^[*-]\s*\[(\s?|x|demo|ack)\]/i', $t)) {
                        $this->errors[] = "$where: \"{$s['title']}\" has a checklist item that is not indented "
                            . 'two spaces, so the wiki does not show it as a list item: ' . self::short($t);
                    } elseif (preg_match('/^\s?[*-]\s+\S/', $line) && !preg_match('/^-{4,}/', $t)) {
                        $this->warnings[] = "$where: \"{$s['title']}\" has a line starting \"" . $t[0]
                            . '" that is not indented two spaces, so it is treated as information: ' . self::short($t);
                    }
                }
                [$text, $refs] = $this->itemRefs($page, $content, $headSources, $sources);
                if ($text === '' && !$refs) continue;
                $items[] = ['id' => ($si + 1) . '.' . (count($items) + 1), 'kind' => $kind, 'text' => $text,
                    'refs' => $refs];
            }
            if ($items) $sections[] = ['title' => $s['title'], 'items' => $items];
        }
        $hasTicks = false;
        foreach ($sections as $s) foreach ($s['items'] as $it) if ($it['kind'] !== 'info') $hasTicks = true;
        if (!$hasTicks) $this->errors[] = "$where: no checklist items (\"  * [ ] ...\", \"  * [demo] ...\" or \"  * [ack] ...\")";

        $ind = [
            'id' => $indId,
            'title' => $title,
            'type' => $type,
            'location' => $location,
            'description' => $doc->intro,
            'refresh_months' => $refresh,
            'self_service' => $self,
            'grants_access' => array_column($resources, 'name'),
            'resources' => $resources,
            'requires' => array_values(array_unique($requires)),
            'sources' => array_values(array_map(fn(Source $s) => $s->summary(), $sources)),
            'sections' => $sections,
            'page' => $page,
        ];
        $ind['version'] = substr(hash('sha256', self::canonical($ind)), 0, 12);
        $ind['wiki'] = ['page' => $page, 'rev' => (int)$r['rev'], 'version' => (string)($r['version'] ?? ''),
            'approved' => $r['approved'] ?? null, 'approved_by' => $r['approved_by'] ?? null];
        $ind['_ok'] = count($this->errors) === $errorsBefore;
        $this->byPage[$page] = $ind;
        return $ind;
    }

    /** Header rows the app uses: field => [label shown in messages, accepted row names]. */
    public const FIELDS = [
        'id' => ['Induction ID', ['induction id']],
        'type' => ['Type', ['type']],
        'location' => ['Location', ['location']],
        'swp' => ['SWP', ['swp', 'swps']],
        'ra' => ['RA', ['ra', 'ras', 'risk assessment', 'risk assessments']],
        'grants' => ['Grants access', ['grants access']],
        'requires' => ['Requires', ['requires']],
        'refresh' => ['Refresh (months)', ['refresh', 'refresh months']],
        'self' => ['Self-service', ['self service', 'selfservice']],
    ];
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
        $out = ['id' => '', 'type' => '', 'location' => '', 'refresh' => 12, 'self' => false, 'resources' => [],
            'requires' => [], 'sources' => []];

        foreach ($doc->badRows as $raw) {
            $err("header row \"" . self::short($raw) . "\" has no value cell; rows look like ^ Label: | value |");
        }
        if (!$doc->rows) {
            $err('no header table found. It goes under the title, one row per field: ^ Induction ID: | IND_... |');
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
                $warn("header row \"{$row['label']}\" isn't used by the inductions app (shown on the page only)");
            }
        }

        $value = [];
        foreach (self::FIELDS as $f => [$label]) {
            $rows = $found[$f] ?? [];
            if (!$rows) {
                $hint = isset($nearMiss[$f]) ? " (is \"{$nearMiss[$f]}\" meant to be \"$label\"?)" : '';
                $err("the header table has no \"$label\" row$hint");
                continue;
            }
            if (count($rows) > 1) $err("the header table has " . count($rows) . " \"$label\" rows; keep one");
            $value[$f] = trim($rows[0]['value']);
            if (preg_match(self::PLACEHOLDER_RE, $value[$f], $pm)) {
                $err("\"$label\" still has the template placeholder {$pm[0]}; fill it in");
                $value[$f] = '';
                continue;
            }
            if ($value[$f] === '') {
                $err(in_array($f, ['swp', 'ra', 'grants', 'requires'], true)
                    ? "\"$label\" is empty; use - for none" : "\"$label\" is empty");
            }
        }
        $plainOf = fn($f) => WikiText::plain($value[$f] ?? '');
        $isNone = fn($f) => in_array(strtolower($plainOf($f)), self::NONE, true);

        // Induction ID
        $id = $plainOf('id');
        if ($id !== '') {
            if (!preg_match('/^[A-Za-z0-9_.-]+$/', $id)) {
                $err("Induction ID \"$id\" may only contain letters, digits, _ . and - (no spaces)");
            }
            $old = $this->knownIds[$page] ?? '';
            if ($old !== '' && $old !== $id) {
                $err("Induction ID changed from $old to $id. Records and card readers use the ID, so change it back; "
                    . 'for a different induction, make a new page');
            }
        }
        $out['id'] = $id;

        // Type
        $type = strtolower($plainOf('type'));
        if ($type !== '' && !in_array($type, ['equipment', 'space'], true)) {
            $err("Type must be \"equipment\" or \"space\", not \"$type\"");
        }
        $out['type'] = $type;
        $out['location'] = $plainOf('location');

        // Refresh (months)
        $r = $plainOf('refresh');
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

        // Grants access: "Name" or "id: Name", comma separated, or -
        if (isset($value['grants']) && $value['grants'] !== '' && !$isNone('grants')) {
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
        if ($type === 'space' && !$out['resources'] && isset($value['grants'])) {
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
                    $err('Requires links to this page; an induction cannot require itself');
                    continue;
                }
                if (!$this->pageExists($rp)) {
                    $err("Requires links to [[$rp]], which doesn't exist");
                    continue;
                }
                $rid = $this->inductionIdOf($rp);
                if ($rid === null) {
                    $warn("Requires links to [[$rp]], which has no approved Induction ID yet");
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
     * Build the bundle from candidate pages (pages tagged with the induction tag). Pages with
     * errors are left out and listed in $failed; the caller decides what to do with them.
     */
    public function build(array $pages): array
    {
        sort($pages);
        $inductions = [];
        foreach ($pages as $page) {
            $ind = $this->buildInduction($page);
            if ($ind) $inductions[] = $ind;
        }
        $this->crossCheck($inductions);
        $bad = [];
        foreach ($this->errors as $e) {
            if (preg_match('/^([^ ]+): /', $e, $m)) $bad[$m[1]] = true;
        }
        $ok = [];
        foreach ($inductions as $i) {
            if (isset($bad[$i['page']]) || !$i['_ok']) {
                $this->failed[] = $i['page'];
                $this->byPage[$i['page']]['_ok'] = false;
                continue;
            }
            unset($i['_ok']);
            $ok[] = $i;
        }
        return self::bundle($ok);
    }

    /** Wrap inductions in a bundle, sorted, with a hash of the checklist content. */
    public static function bundle(array $inductions): array
    {
        usort($inductions, fn($a, $b) => strcmp($a['id'], $b['id']));
        $bundle = [
            'format' => 1,
            'generated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'generator' => 'splinductions DokuWiki plugin',
            'inductions' => $inductions,
        ];
        $bundle['hash'] = substr(hash('sha256', self::canonical(array_map(function ($i) {
            unset($i['wiki']);
            return $i;
        }, $inductions))), 0, 12);
        return $bundle;
    }

    public function crossCheck(array $inductions): void
    {
        $ids = [];
        $known = array_flip($this->knownIds);
        foreach ($inductions as $ind) {
            if ($ind['id'] === '') continue;
            if (isset($ids[$ind['id']])) {
                $this->errors[] = "{$ind['page']}: Induction ID {$ind['id']} is also used by {$ids[$ind['id']]}";
            } else {
                $ids[$ind['id']] = $ind['page'];
            }
        }
        $names = [];
        foreach ($inductions as $ind) {
            foreach ($ind['requires'] as $req) {
                if ($req === $ind['id']) $this->errors[] = "{$ind['page']}: an induction cannot require itself";
                elseif (!isset($ids[$req]) && !isset($known[$req])) {
                    $this->warnings[] = "{$ind['page']}: requires $req, which is not a published induction yet";
                }
            }
            foreach ($ind['resources'] as $res) {
                if (!isset($names[$res['id']])) {
                    $names[$res['id']] = [$res['name'], $ind['id']];
                } elseif ($names[$res['id']][0] !== $res['name']) {
                    $this->errors[] = "{$ind['page']}: access id \"{$res['id']}\" is \"{$res['name']}\" here but "
                        . "\"{$names[$res['id']][0]}\" in {$names[$res['id']][1]}; give them different ids or the same name";
                }
            }
        }
        foreach ($inductions as $ind) {
            if ($ind['type'] !== 'equipment' || $ind['requires']) continue;
            $spaces = [];
            foreach ($inductions as $o) {
                if ($o['type'] === 'space' && $o['location'] === $ind['location']) $spaces[] = $o['id'];
            }
            if ($spaces) {
                $this->warnings[] = "{$ind['page']}: {$ind['id']} is in {$ind['location']} but does not require "
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
