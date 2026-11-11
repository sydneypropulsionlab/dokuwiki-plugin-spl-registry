<?php

use dokuwiki\Extension\Plugin;
use dokuwiki\File\PageResolver;
use dokuwiki\plugin\splinductions\Builder;

/**
 * SPL Inductions: builds the induction bundle from approved induction pages and publishes it
 * as a media file the SPL Inductions app pulls from.
 */
class helper_plugin_splinductions extends Plugin
{
    /** @var array|null approve plugin rows cache */
    protected ?array $approvedCache = null;

    // ----------------------------------------------------------------- settings
    public function tag(): string
    {
        return strtolower(trim($this->getConf('tag'))) ?: 'induction';
    }

    public function mediaId(): string
    {
        return cleanID($this->getConf('media_id'));
    }

    protected function nsList(string $key): array
    {
        return array_values(array_filter(array_map(fn($n) => trim($n, ': '), preg_split('/[\s,]+/', (string)$this->getConf($key)))));
    }

    public function requireApproval(): bool
    {
        return (bool)$this->getConf('require_approval');
    }

    public function bundleUrl(): string
    {
        return ml($this->mediaId(), '', true, '&', true);
    }

    // ----------------------------------------------------------------- reading pages
    /** The approve plugin's database, or null when the plugin is unavailable. */
    protected function approveDb()
    {
        /** @var helper_plugin_approve_db $db */
        $db = plugin_load('helper', 'approve_db');
        if (!$db) return null;
        try {
            return $db->getDB(false);
        } catch (\Exception $e) {
            return null;
        }
    }

    /** page => last approved revision row, for every page. */
    protected function approvedRows(): array
    {
        if ($this->approvedCache !== null) return $this->approvedCache;
        $rows = [];
        $sqlite = $this->approveDb();
        if ($sqlite) {
            $res = $sqlite->query('SELECT page, rev, approved, approved_by, version, minor_version FROM revision
                                    WHERE approved IS NOT NULL ORDER BY page, rev');
            foreach ($sqlite->res2arr($res) as $row) $rows[$row['page']] = $row; // last one wins (highest rev)
        }
        return $this->approvedCache = $rows;
    }

    /** Text of a page at a revision; the current revision may not have an attic copy. */
    protected function revText(string $id, int $rev): string
    {
        $file = wikiFN($id, $rev);
        if (file_exists($file)) return io_readWikiPage($file, $id, $rev);
        if (@filemtime(wikiFN($id)) === $rev) return rawWiki($id);
        return '';
    }

    /** The revision an induction or source page is published from. */
    public function publishedRevision(string $id): ?array
    {
        if (!$this->requireApproval()) {
            if (!page_exists($id)) return null;
            return ['text' => rawWiki($id), 'rev' => (int)@filemtime(wikiFN($id)), 'version' => ''];
        }
        $row = $this->approvedRows()[$id] ?? null;
        if (!$row) return null;
        $text = $this->revText($id, (int)$row['rev']);
        if ($text === '') return null;
        return ['text' => $text, 'rev' => (int)$row['rev'],
            'version' => ($row['version'] ?? 0) . '.' . ($row['minor_version'] ?? 0),
            'approved' => $row['approved'], 'approved_by' => $row['approved_by']];
    }

    public function resolver(): callable
    {
        return fn(string $ctx, string $target) => (new PageResolver($ctx))->resolveId($target);
    }

    protected function builder(?callable $read = null): Builder
    {
        $b = new Builder($read ?? fn($id) => $this->publishedRevision($id), $this->resolver(), $this->tag(),
            $this->nsList('swp_namespaces'), $this->nsList('ra_namespaces'), fn($id) => $this->sourceRevision($id));
        $b->exists = fn($id) => page_exists($id);
        // what the app has now: used to catch a changed Induction ID or a dropped access id
        foreach ($this->publishedBundle()['inductions'] ?? [] as $old) {
            $page = $old['wiki']['page'] ?? ($old['page'] ?? '');
            if ($page === '') continue;
            $b->knownIds[$page] = $old['id'];
            $b->knownResources[$page] = array_column($old['resources'] ?? [], 'id');
        }
        return $b;
    }

    /**
     * An SWP or RA page for attaching text to items: its approved revision if it has one, otherwise
     * its current revision marked as a draft. SWP approval never holds an induction back.
     */
    public function sourceRevision(string $id): ?array
    {
        $r = $this->publishedRevision($id);
        if ($r) return $r;
        if (!page_exists($id)) return null;
        return ['text' => rawWiki($id), 'rev' => (int)@filemtime(wikiFN($id)), 'version' => 'draft'];
    }

    // ----------------------------------------------------------------- new pages
    /**
     * The next free induction page: the highest number used so far in the namespace plus one.
     * Pages that were deleted still count (their history remains), so a number is never reused.
     */
    public function nextPageId(): string
    {
        $ns = trim((string)$this->getConf('namespace'), ': ');
        // not cleanID(): it would strip the trailing - from ind-
        $prefix = preg_replace('/[^a-z0-9_.-]/', '', strtolower((string)$this->getConf('page_prefix'))) ?: 'ind-';
        $max = 0;
        $re = '/^' . preg_quote($prefix, '/') . '(\d+)\.(txt|changes|meta)$/';
        foreach ([dirname(wikiFN("$ns:x")), dirname(metaFN("$ns:x", '.changes'))] as $dir) {
            foreach (@scandir($dir) ?: [] as $f) {
                if (preg_match($re, $f, $m)) $max = max($max, (int)$m[1]);
            }
        }
        return cleanID(($ns !== '' ? "$ns:" : '') . $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT));
    }

    /** Tag the template page carries instead of the induction tag. */
    public function templateTag(): string
    {
        return strtolower(trim((string)$this->getConf('template_tag'))) ?: 'induction-template';
    }

    // ----------------------------------------------------------------- candidates
    /** Namespace templates (c_template, i_template, _template) never count as inductions. */
    public function isTemplate(string $id): bool
    {
        $re = trim((string)$this->getConf('ignore_pages'));
        return $re !== '' && @preg_match('/' . str_replace('/', '\\/', $re) . '/', $id) === 1;
    }

    /** True when the page is inside the induction namespace (or no namespace is set). */
    public function inNamespace(string $id): bool
    {
        $ns = trim((string)$this->getConf('namespace'), ': ');
        return $ns === '' || str_starts_with($id, $ns . ':');
    }

    /**
     * Pages that are, or recently were, tagged as inductions inside the induction namespace: the
     * tag index, a scan of the namespace, and pages already in the published bundle (so removing a
     * tag is noticed). The same tag used elsewhere in the wiki (e.g. FabLab pages) is ignored.
     */
    public function candidates(): array
    {
        global $conf;
        $ids = [];
        try {
            $tag = $this->tag();   // lookupKey takes its value by reference
            foreach (idx_get_indexer()->lookupKey('subject', $tag) as $id) {
                if ($this->inNamespace($id)) $ids[$id] = true;
            }
        } catch (\Throwable $e) {
        }
        $ns = trim((string)$this->getConf('namespace'), ': ');
        if ($ns !== '') {
            $data = [];
            search($data, $conf['datadir'], 'search_allpages', ['depth' => 0, 'skipacl' => true],
                str_replace(':', '/', $ns));
            foreach ($data as $d) {
                // the tag index can lag behind a save, so look at the text itself
                if (!isset($ids[$d['id']]) && $this->isInductionText(rawWiki($d['id']))) $ids[$d['id']] = true;
            }
        }
        foreach (($this->status()['pages'] ?? []) as $id => $_) $ids[$id] = true;
        $out = [];
        foreach (array_keys($ids) as $id) {
            if ($this->isTemplate($id) || !page_exists($id)) continue;
            $out[] = $id;
        }
        sort($out);
        return $out;
    }

    // ----------------------------------------------------------------- status
    protected function statusFile(): string
    {
        global $conf;
        return $conf['metadir'] . '/splinductions.status.json';
    }

    public function status(): array
    {
        $f = $this->statusFile();
        if (!file_exists($f)) return [];
        $s = json_decode((string)io_readFile($f, false), true);
        return is_array($s) ? $s : [];
    }

    protected function saveStatus(array $s): void
    {
        io_saveFile($this->statusFile(), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Cheap fingerprint of everything the bundle depends on: the candidate pages and every
     * approval. A rebuild is only needed when it changes.
     */
    public function fingerprint(): string
    {
        $parts = [$this->getPluginVersion(), $this->tag(), $this->mediaId(), (int)$this->requireApproval(),
            implode(',', $this->candidates())];
        if ($this->requireApproval()) {
            foreach ($this->approvedRows() as $page => $row) $parts[] = "$page@{$row['rev']}";
        } else {
            foreach ($this->candidates() as $id) $parts[] = $id . '@' . @filemtime(wikiFN($id));
        }
        return md5(implode("\n", $parts));
    }

    /** Changes whenever the plugin code changes, so an update rebuilds the bundle. */
    protected function getPluginVersion(): string
    {
        $stamp = '';
        foreach (glob(__DIR__ . '/*.php') as $f) $stamp .= basename($f) . @filemtime($f);
        return md5($stamp);
    }

    // ----------------------------------------------------------------- building and publishing
    /**
     * Rebuild the bundle from published (approved) revisions and publish it if it changed.
     * A failed build leaves the last good bundle in place.
     */
    public function rebuild(string $reason = '', bool $force = false): array
    {
        global $conf;
        $lock = @fopen($conf['lockdir'] . '/splinductions.lock', 'c');
        if ($lock) flock($lock, LOCK_EX);
        try {
            $this->approvedCache = null;
            $fp = $this->fingerprint();
            $status = $this->status();
            if (!$force && ($status['fingerprint'] ?? '') === $fp) return $status;

            $b = $this->builder();
            $bundle = $b->build($this->candidates());
            $now = date('c');
            $status['checked_at'] = $now;
            $status['reason'] = $reason;
            $status['fingerprint'] = $fp;
            $status['errors'] = $b->errors;
            $status['warnings'] = $b->warnings;
            $status['skipped'] = $b->skipped;
            $status['media_id'] = $this->mediaId();
            $status['ok'] = !$b->errors;

            // A page that can't be built keeps the version the app already has (if any).
            $previous = [];
            foreach ($this->publishedBundle()['inductions'] ?? [] as $old) {
                $page = $old['wiki']['page'] ?? ($old['page'] ?? '');
                if ($page !== '') $previous[$page] = $old;
            }
            $inductions = $bundle['inductions'];
            $held = [];
            foreach ($b->failed as $page) {
                if (isset($previous[$page])) {
                    $inductions[] = $previous[$page];
                    $held[$page] = $previous[$page];
                }
            }
            $bundle = Builder::bundle($inductions);

            $pages = [];
            foreach ($bundle['inductions'] as $ind) {
                $page = $ind['wiki']['page'] ?? $ind['page'];
                $pages[$page] = ['id' => $ind['id'], 'title' => $ind['title'], 'version' => $ind['version'],
                    'rev' => $ind['wiki']['rev'] ?? 0, 'wiki_version' => $ind['wiki']['version'] ?? '',
                    'held' => isset($held[$page])];
            }
            $status['pages'] = $pages;
            $status['failed_pages'] = $b->failed;
            if (!$bundle['inductions']) {
                $status['errors'][] = 'No approved induction pages yet, so there is nothing to publish.';
                $status['ok'] = false;
            } elseif (($status['hash'] ?? '') !== $bundle['hash'] || !file_exists(mediaFN($this->mediaId()))) {
                $bundle['wiki'] = DOKU_URL;
                $this->publish($bundle);
                $status['hash'] = $bundle['hash'];
                $status['published_at'] = $now;
                $status['count'] = count($bundle['inductions']);
            }
            $this->saveStatus($status);
            return $status;
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** The bundle currently published, or [] when there is none. */
    public function publishedBundle(): array
    {
        $fn = mediaFN($this->mediaId());
        if (!file_exists($fn)) return [];
        $b = json_decode((string)io_readFile($fn, false), true);
        return is_array($b) ? $b : [];
    }

    /** Write the bundle as a media file, keeping the old one as a media revision. */
    protected function publish(array $bundle): void
    {
        global $conf;
        $id = $this->mediaId();
        $fn = mediaFN($id);
        $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $old = @filemtime($fn);
        if ($old && !file_exists(mediaFN($id, $old))) media_saveOldRevision($id);
        io_createNamespace($id, 'media');
        $sizeOld = file_exists($fn) ? filesize($fn) : 0;
        $tmp = $fn . '.tmp';
        io_saveFile($tmp, $json);
        // the new revision must have a newer timestamp than the old one
        if ($old && $old >= time()) touch($tmp, $old + 1);
        rename($tmp, $fn);
        @clearstatcache(true, $fn);
        chmod($fn, $conf['fmode']);
        addMediaLogEntry(@filemtime($fn), $id, $old ? DOKU_CHANGE_TYPE_EDIT : DOKU_CHANGE_TYPE_CREATE,
            sprintf('Induction bundle %s (%d inductions)', $bundle['hash'], count($bundle['inductions'])),
            '', null, filesize($fn) - $sizeOld);
    }

    /**
     * Check a page as it is being saved: build every induction as it will be published, but
     * with this page's new text. Returns [errors, warnings, induction or null].
     */
    public function checkDraft(string $page, string $text): array
    {
        $this->approvedCache = null;
        $read = fn($id) => $id === $page ? ['text' => $text, 'rev' => time(), 'version' => 'draft']
            : $this->publishedRevision($id);
        $b = $this->builder($read);
        $cands = $this->candidates();
        if (!in_array($page, $cands, true)) $cands[] = $page;
        $b->build($cands);
        $mine = fn($m) => str_starts_with($m, "$page:") || str_contains($m, "[[$page]]");
        return [array_values(array_filter($b->errors, $mine)), array_values(array_filter($b->warnings, $mine)),
            $b->byPage[$page] ?? null];
    }

    public function isInductionText(string $text): bool
    {
        return in_array($this->tag(), \dokuwiki\plugin\splinductions\WikiText::tags($text), true);
    }
}
