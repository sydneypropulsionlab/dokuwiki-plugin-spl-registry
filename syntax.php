<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~REGISTRYSTATUS~~ (or the older ~~INDUCTIONSTATUS~~) shows the published bundle: when it was
 * built, its link, which records are in it, and any build errors or warnings.
 */
class syntax_plugin_splregistry extends SyntaxPlugin
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
        $this->Lexer->addSpecialPattern('~~REGISTRYSTATUS~~', $mode, 'plugin_splregistry');
        $this->Lexer->addSpecialPattern('~~INDUCTIONSTATUS~~', $mode, 'plugin_splregistry');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        return [$match];
    }

    public function render($format, Doku_Renderer $R, $data)
    {
        if ($format !== 'xhtml') return false;
        $R->nocache();
        /** @var helper_plugin_splregistry $h */
        $h = plugin_load('helper', 'splregistry');
        $s = $h->status();
        $mediaId = $h->registryMediaId();
        $out = '<div class="plugin_splregistry_status">';
        $out .= '<p><strong>' . $this->getLang('status_heading') . '</strong> ';
        if (!empty($s['published_at']) && file_exists(mediaFN($mediaId))) {
            $out .= sprintf($this->getLang('status_published'), (int)($s['count_inductions'] ?? 0),
                (int)($s['count_checklists'] ?? 0),
                '<code>' . hsc($s['hash'] ?? '') . '</code>', dformat(strtotime($s['published_at'])));
            $out .= ' <a href="' . $h->bundleUrl() . '">' . hsc($mediaId) . '</a>';
        } else {
            $out .= $this->getLang('status_none');
        }
        $out .= '</p>';
        if (!empty($s['checked_at'])) {
            $out .= '<p class="plugin_splregistry_small">' . sprintf($this->getLang('status_checked'),
                    dformat(strtotime($s['checked_at'])), hsc($s['reason'] ?? '')) . '</p>';
        }
        if (!empty($s['errors'])) {
            $out .= '<div class="plugin_splregistry_note plugin_splregistry_error"><strong>'
                . $this->getLang('status_errors') . '</strong><ul>';
            foreach ($s['errors'] as $e) $out .= '<li>' . $this->linkPages($e) . '</li>';
            $out .= '</ul></div>';
        }
        if (!empty($s['warnings'])) {
            $out .= '<div class="plugin_splregistry_note plugin_splregistry_notify"><strong>'
                . $this->getLang('status_warnings') . '</strong><ul>';
            foreach ($s['warnings'] as $w) $out .= '<li>' . $this->linkPages($w) . '</li>';
            $out .= '</ul></div>';
        }
        if (!empty($s['pages'])) {
            $out .= '<table class="inline"><tr><th>' . $this->getLang('col_record') . '</th><th>'
                . $this->getLang('col_id') . '</th><th>' . $this->getLang('col_type') . '</th><th>'
                . $this->getLang('col_category') . '</th><th>' . $this->getLang('col_approved') . '</th><th>'
                . $this->getLang('col_version') . '</th></tr>';
            foreach ($s['pages'] as $page => $p) {
                $held = !empty($p['held']) ? ' <span class="plugin_splregistry_small">(' . $this->getLang('held') . ')</span>' : '';
                $out .= '<tr><td>' . html_wikilink(':' . $page, $p['title']) . $held . '</td><td><code>' . hsc($p['id'])
                    . '</code></td><td>' . hsc($p['type'] ?? 'induction') . '</td><td>' . hsc($p['category'] ?? '')
                    . '</td><td>v' . hsc($p['wiki_version']) . ' (' . dformat($p['rev']) . ')</td><td><code>'
                    . hsc($p['version']) . '</code></td></tr>';
            }
            $out .= '</table>';
        }
        if (!empty($s['skipped'])) {
            $out .= '<p class="plugin_splregistry_small">' . $this->getLang('status_skipped') . ' ';
            $parts = [];
            foreach ($s['skipped'] as $page => $why) $parts[] = html_wikilink(':' . $page) . ' (' . hsc($why) . ')';
            $out .= implode(', ', $parts) . '</p>';
        }
        $out .= '</div>';
        $R->doc .= $out;
        return true;
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
