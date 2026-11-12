<?php

use dokuwiki\Extension\Plugin;
use dokuwiki\File\PageResolver;
use dokuwiki\plugin\splregistry\Builder;

/**
 * SPL Registry: builds the registry bundle from approved record pages (inductions and
 * checklists) and publishes it as a media file the SPL Registry app pulls from. The induction
 * bundle older versions of the app read is published alongside it.
 */
class helper_plugin_splregistry extends Plugin
{
    /** @var array|null approve plugin rows cache */
    protected ?array $approvedCache = null;

    // ----------------------------------------------------------------- settings
    /** The tag that makes a page a record, wherever it is in the wiki. */
    public function tag(): string
    {
        return strtolower(trim($this->getConf('tag'))) ?: 'record';
    }

    /** @return string[] */
    public function tags(): array
    {
        return [$this->tag()];
    }

    /** Where the registry bundle (format 2) is published. */
    public function registryMediaId(): string
    {
        return cleanID($this->getConf('registry_media_id'));
    }

    /** Where the induction bundle (format 1, for app versions before 0.13) is published, or '' for none. */
    public function mediaId(): string
    {
        return $this->getConf('legacy_bundle') ? cleanID($this->getConf('media_id')) : '';
    }

    protected function nsList(string $key): array
    {
        return array_values(array_filter(array_map(fn($n) => trim($n, ': '), preg_split('/[\s,]+/', (string)$this->getConf($key)))));
    }

    public function requireApproval(): bool
    {
        return (bool)$this->getConf('require_approval');
    }

    public function bundleUrl(?string $id = null): string
    {
        return ml($id ?? $this->registryMediaId(), '', true, '&', true);
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
        $b = new Builder($read ?? fn($id) => $this->publishedRevision($id), $this->resolver(), $this->tags(),
            $this->nsList('swp_namespaces'), $this->nsList('ra_namespaces'), fn($id) => $this->sourceRevision($id));
        $b->exists = fn($id) => page_exists($id);
        // what the app has now: used to catch a changed ID or a dropped access id
        foreach ($this->publishedRecords() as $old) {
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

    /** @return string[] tags a template page carries instead of the record tag */
    public function templateTags(): array
    {
        $tags = preg_split('/[\s,]+/', strtolower(trim((string)$this->getConf('template_tag'))));
        return array_values(array_filter($tags, 'strlen')) ?: ['record-template'];
    }

    // ----------------------------------------------------------------- candidates
    /** Namespace templates (c_template, i_template, _template) never count as records. */
    public function isTemplate(string $id): bool
    {
        $re = trim((string)$this->getConf('ignore_pages'));
        return $re !== '' && @preg_match('/' . str_replace('/', '\\/', $re) . '/', $id) === 1;
    }

    /**
     * Pages that are, or recently were, records: pages tagged with the record tag (a record page is
     * indexed as soon as it is saved), and pages in the last build (so removing a tag is noticed).
     */
    public function candidates(): array
    {
        $ids = [];
        try {
            $tag = $this->tag();   // lookupKey takes its value by reference
            foreach (idx_get_indexer()->lookupKey('subject', $tag) as $id) $ids[$id] = true;
        } catch (\Throwable $e) {
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
        return $conf['metadir'] . '/splregistry.status.json';
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
        $parts = [$this->getPluginVersion(), implode(',', $this->tags()), $this->registryMediaId(), $this->mediaId(),
            (int)$this->requireApproval(),
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
     * A page that fails to build keeps the version the app already has.
     */
    public function rebuild(string $reason = '', bool $force = false): array
    {
        global $conf;
        $lock = @fopen($conf['lockdir'] . '/splregistry.lock', 'c');
        if ($lock) flock($lock, LOCK_EX);
        try {
            $this->approvedCache = null;
            $fp = $this->fingerprint();
            $status = $this->status();
            if (!$force && ($status['fingerprint'] ?? '') === $fp) return $status;

            $b = $this->builder();
            $records = $b->build($this->candidates());
            $now = date('c');
            $status['checked_at'] = $now;
            $status['reason'] = $reason;
            $status['fingerprint'] = $fp;
            $status['errors'] = $b->errors;
            $status['warnings'] = $b->warnings;
            $status['skipped'] = $b->skipped;
            $status['media_id'] = $this->registryMediaId();
            $status['ok'] = !$b->errors;

            // A page that can't be built keeps the version the app already has (if any).
            $previous = [];
            foreach ($this->publishedRecords() as $old) {
                $page = $old['wiki']['page'] ?? ($old['page'] ?? '');
                if ($page !== '') $previous[$page] = $old;
            }
            $held = [];
            foreach ($b->failed as $page) {
                if (isset($previous[$page])) {
                    $records[] = $previous[$page];
                    $held[$page] = true;
                }
            }
            $bundle = Builder::bundle($records);

            $pages = [];
            foreach (array_merge($bundle['inductions'], $bundle['checklists']) as $rec) {
                $page = $rec['wiki']['page'] ?? $rec['page'];
                $pages[$page] = ['id' => $rec['id'], 'title' => $rec['title'], 'version' => $rec['version'],
                    'type' => $rec['record_type'] ?? 'induction', 'category' => $rec['category'] ?? '',
                    'rev' => $rec['wiki']['rev'] ?? 0, 'wiki_version' => $rec['wiki']['version'] ?? '',
                    'held' => isset($held[$page])];
            }
            $status['pages'] = $pages;
            $status['failed_pages'] = $b->failed;
            if (!$pages) {
                $status['errors'][] = 'No approved record pages yet, so there is nothing to publish.';
                $status['ok'] = false;
            } else {
                $regId = $this->registryMediaId();
                if (($status['hash'] ?? '') !== $bundle['hash'] || !file_exists(mediaFN($regId))) {
                    $bundle['wiki'] = DOKU_URL;
                    $this->publish($regId, $bundle, sprintf('Registry bundle %s (%d inductions, %d checklists)',
                        $bundle['hash'], count($bundle['inductions']), count($bundle['checklists'])));
                    $status['hash'] = $bundle['hash'];
                    $status['published_at'] = $now;
                }
                $status['count'] = count($pages);
                $status['count_inductions'] = count($bundle['inductions']);
                $status['count_checklists'] = count($bundle['checklists']);
                // the format 1 bundle, for app versions that read inductions.json
                $legacyId = $this->mediaId();
                $legacy = Builder::legacyBundle($records);
                if ($legacyId !== '' && $legacy['inductions']
                    && (($status['legacy_hash'] ?? '') !== $legacy['hash'] || !file_exists(mediaFN($legacyId)))) {
                    $legacy['wiki'] = DOKU_URL;
                    $this->publish($legacyId, $legacy, sprintf('Induction bundle %s (%d inductions)',
                        $legacy['hash'], count($legacy['inductions'])));
                    $status['legacy_hash'] = $legacy['hash'];
                }
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

    /** The bundle currently published: the registry bundle, or the induction bundle before there was one. */
    public function publishedBundle(): array
    {
        foreach ([$this->registryMediaId(), $this->mediaId()] as $id) {
            if ($id === '' || !file_exists($fn = mediaFN($id))) continue;
            $b = json_decode((string)io_readFile($fn, false), true);
            if (is_array($b)) return $b;
        }
        return [];
    }

    /** Every record in the published bundle. */
    public function publishedRecords(): array
    {
        $b = $this->publishedBundle();
        return array_merge($b['inductions'] ?? [], $b['checklists'] ?? []);
    }

    /** Write a bundle as a media file, keeping the old one as a media revision. */
    protected function publish(string $id, array $bundle, string $summary): void
    {
        global $conf;
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
        addMediaLogEntry(@filemtime($fn), $id, $old ? DOKU_CHANGE_TYPE_EDIT : DOKU_CHANGE_TYPE_CREATE, $summary,
            '', null, filesize($fn) - $sizeOld);
    }

    /**
     * Check a page as it is being saved: build every record as it will be published, but
     * with this page's new text. Returns [errors, warnings, record or null].
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

    /** True when the text is tagged as a record. */
    public function isRecordText(string $text, ?string $id = null): bool
    {
        return in_array($this->tag(), \dokuwiki\plugin\splregistry\WikiText::tags($text), true);
    }
}
