<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

/**
 * When the bundle is rebuilt:
 *  - right after a page is approved (the approve plugin redirects, so this runs at shutdown);
 *  - in the background task runner whenever approvals or tagged pages have changed (a safety net
 *    that also catches deletions, moves and approvals made any other way).
 * On save, an induction page is checked as a draft and the editor sees any problems at once.
 */
class action_plugin_splinductions extends ActionPlugin
{
    public function register(EventHandler $controller)
    {
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'handleSave');
        // before the approve plugin, which redirects and exits once it has stored the approval
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleApprove', null, -100);
        $controller->register_hook('INDEXER_TASKS_RUN', 'BEFORE', $this, 'handleTaskRunner');
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'handlePageStatus');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleNewInduction');
        $controller->register_hook('COMMON_PAGETPL_LOAD', 'AFTER', $this, 'handleTemplate');
    }

    protected function helper(): helper_plugin_splinductions
    {
        return plugin_load('helper', 'splinductions');
    }

    protected function isWatched(string $id, string $text): bool
    {
        $h = $this->helper();
        if ($h->isTemplate($id) || !$h->inNamespace($id)) return false;
        return $h->isInductionText($text) || isset($h->status()['pages'][$id]);
    }

    public function handleSave(Event $event)
    {
        $id = $event->data['id'];
        $text = (string)$event->data['newContent'];
        $h0 = $this->helper();
        if ($h0->inNamespace($id)) {
            // update the search and tag index now rather than on a later page view, so a new or
            // retagged page shows in the induction list straight away
            register_shutdown_function(function () use ($id) {
                try {
                    (new \dokuwiki\Search\Indexer())->addPage($id, true);
                } catch (\Throwable $e) {
                    \dokuwiki\Logger::error('splinductions: indexing failed', $e->getMessage());
                }
            });
        }
        if (!$this->isWatched($id, $text)) return;
        $h = $this->helper();
        if (!$h->isInductionText($text)) {
            msg(sprintf($this->getLang('untagged'), $h->tag()), 2);
            return;
        }
        [$errors, $warnings] = $h->checkDraft($id, $text);
        foreach (array_slice($errors, 0, 15) as $e) msg(hsc($this->getLang('check_error') . ' ' . $e), -1);
        if (count($errors) > 15) msg(sprintf($this->getLang('more_errors'), count($errors) - 15), -1);
        foreach (array_slice($warnings, 0, 5) as $w) msg(hsc($this->getLang('check_warning') . ' ' . $w), 2);
        if (!$errors) {
            msg($h->requireApproval() ? $this->getLang('check_ok_approve') : $this->getLang('check_ok'), 1);
            if (!$h->requireApproval()) register_shutdown_function([$h, 'rebuild'], "saved $id");
        }
    }

    /**
     * Preview: run the same check as on save against the text in the editor (the whole page, also
     * for a section edit) and show the result above the editor, before anything is saved.
     */
    protected function previewCheck(): void
    {
        global $ID, $TEXT, $PRE, $SUF;
        $text = con((string)$PRE, (string)$TEXT, (string)$SUF);
        if (!$this->isWatched($ID, $text)) return;
        $h = $this->helper();
        if (!$h->isInductionText($text)) {
            echo '<div class="plugin_splinductions_note plugin_splinductions_notify"><strong>'
                . $this->getLang('preview_heading') . '</strong> ' . sprintf($this->getLang('untagged'), hsc($h->tag()))
                . '</div>';
            return;
        }
        try {
            [$errors, $warnings, $ind] = $h->checkDraft($ID, $text);
        } catch (\Throwable $e) {
            echo '<div class="plugin_splinductions_note plugin_splinductions_error"><strong>'
                . $this->getLang('preview_heading') . '</strong> ' . hsc($e->getMessage()) . '</div>';
            return;
        }
        $strip = fn($m) => str_starts_with($m, "$ID: ") ? substr($m, strlen($ID) + 2) : $m;
        if ($errors) {
            $cls = 'error';
            $msg = sprintf($this->getLang('preview_errors'), count($errors));
        } elseif ($warnings) {
            $cls = 'notify';
            $msg = $this->getLang('preview_warnings');
        } else {
            $cls = 'info';
            $msg = $this->getLang('preview_ok');
        }
        echo '<div class="plugin_splinductions_note plugin_splinductions_' . $cls . '">';
        echo '<strong>' . $this->getLang('preview_heading') . '</strong> ' . $msg;
        foreach ([[$errors, ''], [$warnings, $errors ? $this->getLang('page_warnings') : '']] as [$items, $head]) {
            if (!$items) continue;
            if ($head !== '') echo '<div class="plugin_splinductions_small">' . $head . '</div>';
            echo '<ul>';
            foreach ($items as $m) echo '<li>' . hsc($strip($m)) . '</li>';
            echo '</ul>';
        }
        if ($ind && !$errors) {
            $items = 0;
            $refs = 0;
            $kinds = ['check' => 0, 'demo' => 0, 'ack' => 0, 'info' => 0];
            foreach ($ind['sections'] as $s) {
                foreach ($s['items'] as $it) {
                    $items++;
                    $kinds[$it['kind']]++;
                    $refs += count($it['refs']);
                }
            }
            echo '<div class="plugin_splinductions_small">' . sprintf($this->getLang('preview_summary'),
                    hsc($ind['id']), count($ind['sections']), $kinds['check'], $kinds['demo'], $kinds['ack'],
                    $kinds['info'], $refs) . '</div>';
        }
        echo '</div>';
    }

    /**
     * ?do=newinduction : work out the next free ind-NNN now (not when the button was drawn, so two
     * people can't get the same number) and open it in the editor. The new page starts from the
     * namespace template (c_template, via the templatepagename plugin).
     */
    public function handleNewInduction(Event $event)
    {
        if ($event->data !== 'newinduction') return;
        $event->preventDefault();
        $event->stopPropagation();
        $new = $this->helper()->nextPageId();
        if (auth_quickaclcheck($new) < AUTH_CREATE) {
            msg($this->getLang('new_denied'), -1);
            $event->data = 'show';
            return;
        }
        send_redirect(wl($new, ['do' => 'edit'], true, '&'));
    }

    /**
     * The template page is tagged with the template tag (induction-template), so it shows in the
     * sitemap but not in induction lists and never becomes an induction itself. A new page made
     * from it gets the real induction tag instead.
     */
    public function handleTemplate(Event $event)
    {
        $h = $this->helper();
        $id = (string)$event->data['id'];
        if (!$h->inNamespace($id) || $h->isTemplate($id)) return;
        $from = preg_quote($h->templateTag(), '/');
        // the template's own title (~~META:title=c_template~~) is for the template page only
        $event->data['tpl'] = preg_replace('/^[ \t]*~~META:title\s*=[^\n]*~~[ \t]*\r?\n?/mi', '',
            (string)$event->data['tpl']);
        $event->data['tpl'] = preg_replace_callback('/\{\{tag>([^}]*)\}\}/i', function ($m) use ($from, $h) {
            return '{{tag>' . preg_replace('/(^|\s)' . $from . '(?=\s|$)/i', '${1}' . $h->tag(), $m[1]) . '}}';
        }, (string)$event->data['tpl']);
    }

    public function handleApprove(Event $event)
    {
        global $INPUT, $ID;
        if ($event->data !== 'show' || !$INPUT->has('approve')) return;
        $h = $this->helper();
        register_shutdown_function(function () use ($h, $ID) {
            try {
                $h->rebuild("approved $ID");
            } catch (\Throwable $e) {
                \dokuwiki\Logger::error('splinductions: rebuild after approval failed', $e->getMessage());
            }
        });
    }

    /**
     * Background check: rebuild when approvals or induction pages changed. It runs alongside
     * DokuWiki's own background jobs (search and tag indexing) rather than instead of them.
     */
    public function handleTaskRunner(Event $event)
    {
        $h = $this->helper();
        if (($h->status()['fingerprint'] ?? '') === $h->fingerprint()) return;
        try {
            $h->rebuild('background check');
        } catch (\Throwable $e) {
            \dokuwiki\Logger::error('splinductions: background rebuild failed', $e->getMessage());
        }
    }

    /** A short note above an induction page: is this revision what the app is using? */
    public function handlePageStatus(Event $event)
    {
        global $ID, $INFO;
        if ($event->data === 'preview') {
            $this->previewCheck();
            return;
        }
        if ($event->data !== 'show' || !page_exists($ID)) return;
        $h = $this->helper();
        if ($h->isTemplate($ID) || !$h->inNamespace($ID)) return;
        $status = $h->status();
        $pub = $status['pages'][$ID] ?? null;
        $failed = in_array($ID, $status['failed_pages'] ?? [], true);
        if (!$pub && !$failed && !$h->isInductionText(rawWiki($ID))) return;

        $mine = fn($m) => str_starts_with($m, "$ID: ");
        $errs = array_values(array_filter($status['errors'] ?? [], $mine));
        $warns = array_values(array_filter($status['warnings'] ?? [], $mine));
        if ($errs) {
            $cls = 'error';
            $msg = $pub ? sprintf($this->getLang('page_held'), hsc($pub['id']), hsc($pub['wiki_version']))
                : $this->getLang('page_errors');
        } elseif ($pub) {
            $current = (int)($INFO['lastmod'] ?? 0) === (int)$pub['rev'];
            $msg = sprintf($this->getLang($current ? 'page_published' : 'page_published_older'),
                hsc($pub['id']), hsc($pub['wiki_version']), dformat($pub['rev']));
            $cls = $current ? ($warns ? 'notify' : 'info') : 'notify';
        } else {
            $cls = 'notify';
            $msg = $h->requireApproval() ? $this->getLang('page_not_approved') : $this->getLang('page_not_published');
        }
        echo '<div class="plugin_splinductions_note plugin_splinductions_' . $cls . '">';
        echo '<strong>' . $this->getLang('page_heading') . '</strong> ' . $msg;
        $list = function ($items, $heading) use ($ID) {
            if (!$items) return;
            if ($heading !== '') echo '<div class="plugin_splinductions_small">' . $heading . '</div>';
            echo '<ul>';
            foreach ($items as $e) echo '<li>' . hsc(substr($e, strlen($ID) + 2)) . '</li>';
            echo '</ul>';
        };
        $list($errs, '');
        $list($warns, $this->getLang('page_warnings'));
        echo '</div>';
    }
}
