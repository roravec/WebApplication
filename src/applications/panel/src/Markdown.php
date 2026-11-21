<?php
namespace Panel;

/**
 * Small, safe Markdown → HTML for the guides of the Pomocník (help/*.md) — no library needed, every text is escaped.
 * Supported: # ## ### headings, paragraphs, - / * and 1. lists (one nested level by 2+ spaces), > notes, | tables |,
 * --- rule, **bold**, *italic*, `code`, [text](link). Links: "/path" = a page of the panel, "help:slug" = another guide,
 * http(s) = external (new tab).
 */
class Markdown
{
    /** @param callable $url fn(string $panelPath): string — absolute URL of a panel page */
    public static function toHtml(string $md, callable $url): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $md);
        $out = [];
        $para = [];
        $list = null;   // ['type' => ul|ol, 'items' => [[text, children[]]]]
        $flushPara = function () use (&$para, &$out, $url) {
            if ($para) {
                $out[] = '<p>' . self::inline(implode(' ', $para), $url) . '</p>';
                $para = [];
            }
        };
        $flushList = function () use (&$list, &$out, $url) {
            if ($list) {
                $html = '<' . $list['type'] . '>';
                foreach ($list['items'] as $item) {
                    $html .= '<li>' . self::inline($item[0], $url);
                    if ($item[1]) {
                        $html .= '<' . $item[2] . '>';
                        foreach ($item[1] as $child) {
                            $html .= '<li>' . self::inline($child, $url) . '</li>';
                        }
                        $html .= '</' . $item[2] . '>';
                    }
                    $html .= '</li>';
                }
                $out[] = $html . '</' . $list['type'] . '>';
                $list = null;
            }
        };
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $line = rtrim($lines[$i]);
            if (trim($line) === '') {
                $flushPara();
                $flushList();
                continue;
            }
            if (preg_match('/^(#{1,3})\s+(.*)$/', $line, $m)) {
                $flushPara();
                $flushList();
                $level = strlen($m[1]);
                $out[] = '<h' . ($level + 1) . ' id="' . self::slug($m[2]) . '">' . self::inline($m[2], $url) . '</h' . ($level + 1) . '>';
                continue;
            }
            if (preg_match('/^-{3,}$/', trim($line))) {
                $flushPara();
                $flushList();
                $out[] = '<hr>';
                continue;
            }
            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $flushPara();
                $flushList();
                $quote = [$m[1]];
                while ($i + 1 < $n && preg_match('/^>\s?(.*)$/', rtrim($lines[$i + 1]), $mm)) {
                    $quote[] = $mm[1];
                    $i++;
                }
                $out[] = '<blockquote>' . self::inline(implode(' ', $quote), $url) . '</blockquote>';
                continue;
            }
            if (strpos(ltrim($line), '|') === 0) {
                $flushPara();
                $flushList();
                $rows = [$line];
                while ($i + 1 < $n && strpos(ltrim($lines[$i + 1]), '|') === 0) {
                    $rows[] = rtrim($lines[++$i]);
                }
                $out[] = self::table($rows, $url);
                continue;
            }
            if (preg_match('/^(\s*)([-*]|\d+\.)\s+(.*)$/', $line, $m)) {
                $flushPara();
                $type = ctype_digit(rtrim($m[2], '.')) ? 'ol' : 'ul';
                if (strlen($m[1]) >= 2 && $list && $list['items']) {   // nested item
                    $last = count($list['items']) - 1;
                    $list['items'][$last][1][] = $m[3];
                    $list['items'][$last][2] = $type;
                    continue;
                }
                if ($list && $list['type'] !== $type) {
                    $flushList();
                }
                if (!$list) {
                    $list = ['type' => $type, 'items' => []];
                }
                $list['items'][] = [$m[3], [], 'ul'];
                continue;
            }
            if ($list && preg_match('/^\s{2,}(\S.*)$/', $line, $m)) {   // continuation of a list item
                $last = count($list['items']) - 1;
                if ($list['items'][$last][1]) {
                    $c = count($list['items'][$last][1]) - 1;
                    $list['items'][$last][1][$c] .= ' ' . $m[1];
                } else {
                    $list['items'][$last][0] .= ' ' . $m[1];
                }
                continue;
            }
            $flushList();
            $para[] = trim($line);
        }
        $flushPara();
        $flushList();
        return implode("\n", $out);
    }

    /** Title of a guide = its first "# " heading. */
    public static function title(string $md): string
    {
        return preg_match('/^#\s+(.+)$/m', $md, $m) ? trim($m[1]) : '';
    }

    private static function table(array $rows, callable $url): string
    {
        $cells = function ($row) { return array_map('trim', explode('|', trim(trim($row), '|'))); };
        $html = '<table>';
        $body = false;
        foreach ($rows as $k => $row) {
            if ($k === 1 && preg_match('/^\|?[\s:|-]+\|?$/', trim($row))) {
                $body = true;
                continue;
            }
            $tag = $k === 0 && isset($rows[1]) && preg_match('/^\|?[\s:|-]+\|?$/', trim($rows[1])) ? 'th' : 'td';
            $html .= '<tr>';
            foreach ($cells($row) as $c) {
                $html .= "<$tag>" . self::inline($c, $url) . "</$tag>";
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    /** Inline formatting of an escaped text. */
    private static function inline(string $text, callable $url): string
    {
        $codes = [];
        $text = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
            $codes[] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
            return "\x00" . (count($codes) - 1) . "\x00";
        }, $text);
        $links = [];
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) use (&$links, $url) {
            $href = $m[2];
            $external = (bool)preg_match('#^https?://#i', $href);
            if (strpos($href, 'help:') === 0) {
                $href = $url('/help/' . preg_replace('/[^a-z0-9-]/', '', substr($href, 5)));
            } elseif (strpos($href, '/') === 0) {
                $href = $url($href);
            } elseif (!$external) {
                $href = '#' . self::slug($href);
            }
            $links[] = '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . ($external ? ' target="_blank" rel="noopener"' : '')
                . '>' . self::emphasis(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')) . '</a>';
            return "\x01" . (count($links) - 1) . "\x01";
        }, $text);
        $text = self::emphasis(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        $text = preg_replace_callback('/\x01(\d+)\x01/', function ($m) use ($links) { return $links[(int)$m[1]]; }, $text);
        return preg_replace_callback('/\x00(\d+)\x00/', function ($m) use ($codes) { return $codes[(int)$m[1]]; }, $text);
    }

    private static function emphasis(string $escaped): string
    {
        $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $escaped);
        return preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/u', '<i>$1</i>', $escaped);
    }

    public static function slug(string $text): string
    {
        $t = strtr(mb_strtolower($text, 'UTF-8'), ['á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'í' => 'i', 'ĺ' => 'l',
            'ľ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ŕ' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ý' => 'y', 'ž' => 'z']);
        return trim(preg_replace('/[^a-z0-9]+/', '-', $t), '-');
    }
}
