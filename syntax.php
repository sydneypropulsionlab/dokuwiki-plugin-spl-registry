<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~INDUCTIONSTATUS~~ shows the published bundle: when it was built, its link, which
 * inductions are in it, and any build errors or warnings.
 */
class syntax_plugin_splinductions extends SyntaxPlugin
{
    public function getType()
    {
        return 'substition';
    }

    public function getPType()
    {
        return 'block';
    }

    public function getSort()
    {
        return 160;
    }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~INDUCTIONSTATUS~~', $mode, 'plugin_splinductions');
        $this->Lexer->addSpecialPattern('~~NEWINDUCTION~~', $mode, 'plugin_splinductions');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        return [$match];
    }

    public function render($format, Doku_Renderer $R, $data)
    {
        if ($format !== 'xhtml') return false;
        $R->nocache();
        if (($data[0] ?? '') === '~~NEWINDUCTION~~') {
            $R->doc .= $this->newButtons();
            return true;
        }
        /** @var helper_plugin_splinductions $h */
        $h = plugin_load('helper', 'splinductions');
        $s = $h->status();
        $mediaId = $h->mediaId();
        $out = '<div class="plugin_splinductions_status">';
        $out .= '<p><strong>' . $this->getLang('status_heading') . '</strong> ';
        if (!empty($s['published_at']) && file_exists(mediaFN($mediaId))) {
            $out .= sprintf($this->getLang('status_published'), (int)($s['count'] ?? 0),
                '<code>' . hsc($s['hash'] ?? '') . '</code>', dformat(strtotime($s['published_at'])));
            $out .= ' <a href="' . $h->bundleUrl() . '">' . hsc($mediaId) . '</a>';
        } else {
            $out .= $this->getLang('status_none');
        }
        $out .= '</p>';
        if (!empty($s['checked_at'])) {
            $out .= '<p class="plugin_splinductions_small">' . sprintf($this->getLang('status_checked'),
                    dformat(strtotime($s['checked_at'])), hsc($s['reason'] ?? '')) . '</p>';
        }
        if (!empty($s['errors'])) {
            $out .= '<div class="plugin_splinductions_note plugin_splinductions_error"><strong>'
                . $this->getLang('status_errors') . '</strong><ul>';
            foreach ($s['errors'] as $e) $out .= '<li>' . $this->linkPages($e) . '</li>';
            $out .= '</ul></div>';
        }
        if (!empty($s['warnings'])) {
            $out .= '<div class="plugin_splinductions_note plugin_splinductions_notify"><strong>'
                . $this->getLang('status_warnings') . '</strong><ul>';
            foreach ($s['warnings'] as $w) $out .= '<li>' . $this->linkPages($w) . '</li>';
            $out .= '</ul></div>';
        }
        if (!empty($s['pages'])) {
            $out .= '<table class="inline"><tr><th>' . $this->getLang('col_induction') . '</th><th>'
                . $this->getLang('col_id') . '</th><th>' . $this->getLang('col_approved') . '</th><th>'
                . $this->getLang('col_version') . '</th></tr>';
            foreach ($s['pages'] as $page => $p) {
                $held = !empty($p['held']) ? ' <span class="plugin_splinductions_small">(' . $this->getLang('held') . ')</span>' : '';
                $out .= '<tr><td>' . html_wikilink(':' . $page, $p['title']) . $held . '</td><td><code>' . hsc($p['id'])
                    . '</code></td><td>v' . hsc($p['wiki_version']) . ' (' . dformat($p['rev']) . ')</td><td><code>'
                    . hsc($p['version']) . '</code></td></tr>';
            }
            $out .= '</table>';
        }
        if (!empty($s['skipped'])) {
            $out .= '<p class="plugin_splinductions_small">' . $this->getLang('status_skipped') . ' ';
            $parts = [];
            foreach ($s['skipped'] as $page => $why) $parts[] = html_wikilink(':' . $page) . ' (' . hsc($why) . ')';
            $out .= implode(', ', $parts) . '</p>';
        }
        $out .= '</div>';
        $R->doc .= $out;
        return true;
    }

    /** A button that creates the next ind-NNN page from the template. */
    protected function newButtons(): string
    {
        global $ID;
        /** @var helper_plugin_splinductions $h */
        $h = plugin_load('helper', 'splinductions');
        $next = $h->nextPageId();
        if (auth_quickaclcheck($next) < AUTH_CREATE) return '';
        $url = wl($ID, ['do' => 'newinduction']);
        return '<div class="plugin_splinductions_new"><a class="btn btn-primary" href="' . $url . '" rel="nofollow">'
            . $this->getLang('new_button') . '</a><div class="plugin_splinductions_small">'
            . sprintf($this->getLang('new_next'), '<code>' . hsc(noNS($next)) . '</code>') . '</div></div>';
    }

    /** Turn the page id at the start of a message into a link. */
    protected function linkPages(string $msg): string
    {
        if (preg_match('/^([a-z0-9_.:-]+): (.*)$/s', $msg, $m) && page_exists($m[1])) {
            return html_wikilink(':' . $m[1]) . ': ' . hsc($m[2]);
        }
        return hsc($msg);
    }
}
