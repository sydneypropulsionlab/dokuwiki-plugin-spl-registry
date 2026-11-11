<?php

namespace dokuwiki\plugin\splinductions;

/** Inline DokuWiki markup to plain text, and link parsing. */
class WikiText
{
    public const LINK_RE = '/\[\[(.+?)\]\]/';

    /** Split the inside of [[...]] into target, anchor and label. */
    public static function link(string $inner): array
    {
        [$target, $label] = array_pad(explode('|', $inner, 2), 2, null);
        $target = trim($target);
        $anchor = '';
        if (($p = strpos($target, '#')) !== false) {
            $anchor = substr($target, $p + 1);
            $target = substr($target, 0, $p);
        }
        $external = (bool)preg_match('#^([a-z][a-z0-9+.-]*://|mailto:|\\\\\\\\|[a-z0-9_-]+>)#i', $target);
        return ['target' => $target, 'anchor' => $anchor, 'label' => $label === null ? null : trim($label),
            'external' => $external];
    }

    /** The visible text of a link. */
    public static function linkText(array $l): string
    {
        if ($l['label'] !== null && $l['label'] !== '') return $l['label'];
        if ($l['external']) return $l['target'];
        $t = $l['target'] !== '' ? $l['target'] : $l['anchor'];
        $parts = explode(':', $t);
        return str_replace('_', ' ', end($parts));
    }

    public static function plain(string $s): string
    {
        $s = preg_replace('/\(\(.*?\)\)/s', '', $s);                  // footnotes
        $s = preg_replace_callback(self::LINK_RE, fn($m) => self::linkText(self::link($m[1])), $s);
        $s = preg_replace('/\{\{[^}]*\}\}/', '', $s);                 // images, tag lists
        $s = preg_replace('/<\/?[a-z][^>]*>/i', '', $s);              // plugin tags
        $s = str_replace(['**', '__', "''", '%%'], '', $s);
        $s = preg_replace('#(?<![:/])//#', '', $s);                   // italics, not URLs
        $s = str_replace('\\\\', ' ', $s);                            // forced line breaks
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /** Stable machine id: "Form 4 3D Printers" -> "form-4-3d-printers". */
    public static function slug(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    }

    /** Tags in {{tag>a b c}} on a page. */
    public static function tags(string $text): array
    {
        $tags = [];
        $text = WikiDoc::stripComments($text);
        if (preg_match_all('/\{\{tag>([^}]*)\}\}/i', $text, $m)) {
            foreach ($m[1] as $list) {
                foreach (preg_split('/\s+/', trim($list)) as $t) {
                    if ($t !== '') $tags[] = strtolower(str_replace('_', ' ', $t));
                }
            }
        }
        return $tags;
    }
}
