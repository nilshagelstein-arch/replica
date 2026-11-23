<?php
/**
 * Kleiner PDF-Erzeuger ohne Fremdbibliothek: A4 hoch oder quer, Schriften Helvetica und Helvetica-Bold
 * (Standardschriften jedes PDF-Betrachters, Zeichensatz WinAnsi/Windows-1252 – Umlaute, ß, €, „“ und – gehen).
 * Reicht für Listen und Tabellen (z. B. die Fristenübersicht der Verträge); Seitenumbruch mit wiederholtem
 * Tabellenkopf und Fußzeile „Seite x von y“.
 */

declare(strict_types=1);

final class SimplePdf
{
    /** Zeichenbreiten von Helvetica in 1/1000 der Schriftgröße (ASCII 32–126), sonst 556 */
    private const W = [' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191, '(' => 333, ')' => 333,
        '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584,
        '?' => 556, '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278,
        'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611,
        'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556,
        '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556, 'h' => 556, 'i' => 222, 'j' => 222,
        'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556,
        'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500, '{' => 334, '|' => 260, '}' => 334, '~' => 584,
        "\xC4" => 667, "\xD6" => 778, "\xDC" => 722, "\xDF" => 611, "\x84" => 333, "\x93" => 333, "\x94" => 333, "\x96" => 556, "\x85" => 1000, "\xB7" => 278];

    private array $pages = [];
    /** Eingebettete JPEG-Bilder: [Daten, Breite px, Höhe px] */
    private array $images = [];
    private string $cur = '';
    public float $y = 0;
    public readonly float $w;
    public readonly float $h;
    public float $margin = 40;
    /** Wird bei jeder neuen Seite aufgerufen (z. B. Tabellenkopf wiederholen) */
    public ?Closure $onNewPage = null;
    private string $footerLeft = '';

    public function __construct(bool $landscape = false, string $footerLeft = '')
    {
        [$this->w, $this->h] = $landscape ? [841.89, 595.28] : [595.28, 841.89];
        $this->footerLeft = $footerLeft;
        $this->addPage(false);
    }

    public function addPage(bool $callHook = true): void
    {
        if ($this->cur !== '') $this->pages[] = $this->cur;
        $this->cur = ' ';
        $this->y = $this->h - $this->margin;
        if ($callHook && $this->onNewPage) ($this->onNewPage)($this);
    }

    /** UTF-8 nach Windows-1252 (WinAnsi); nicht darstellbare Zeichen werden ersetzt */
    public static function enc(string $s): string
    {
        $s = str_replace(["\r", "\n", "\t"], ' ', $s);
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
    }

    public static function width(string $utf8, float $size, bool $bold = false): float
    {
        $s = self::enc($utf8);
        $w = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) $w += self::W[$s[$i]] ?? 556;
        return $w * $size / 1000 * ($bold ? 1.07 : 1.0);
    }

    /** Text auf Breite kürzen (mit …) */
    public static function fit(string $utf8, float $maxW, float $size, bool $bold = false): string
    {
        if (self::width($utf8, $size, $bold) <= $maxW) return $utf8;
        $chars = mb_str_split($utf8);
        while ($chars && self::width(implode('', $chars) . '…', $size, $bold) > $maxW) array_pop($chars);
        return rtrim(implode('', $chars)) . '…';
    }

    /** Text in Zeilen umbrechen */
    public static function wrap(string $utf8, float $maxW, float $size, bool $bold = false): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($utf8)) ?: [] as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && self::width($try, $size, $bold) > $maxW) { $lines[] = $line; $line = $word; }
            else $line = $try;
        }
        if ($line !== '') $lines[] = $line;
        return array_map(fn($l) => self::fit($l, $maxW, $size, $bold), $lines ?: ['']);
    }

    private static function esc(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], self::enc($s));
    }

    public function text(float $x, float $y, string $utf8, float $size = 10, bool $bold = false, array $rgb = [0, 0, 0], string $align = 'left'): void
    {
        if ($align === 'right') $x -= self::width($utf8, $size, $bold);
        $c = sprintf('%.3F %.3F %.3F rg ', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
        $this->cur .= sprintf("BT %s/F%d %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $c, $bold ? 2 : 1, $size, $x, $y, self::esc($utf8));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $rgb = [200, 205, 212]): void
    {
        $this->cur .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255, $width, $x1, $y1, $x2, $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->cur .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255, $x, $y, $w, $h);
    }

    /** JPEG-Bild an Position (x, y = Unterkante) mit Breite/Höhe in Punkt */
    public function imageJpeg(string $jpeg, float $x, float $y, float $w, float $h): bool
    {
        $info = @getimagesizefromstring($jpeg);
        if (!$info || ($info[2] ?? 0) !== IMAGETYPE_JPEG) return false;
        $this->images[] = [$jpeg, (int)$info[0], (int)$info[1], ($info['channels'] ?? 3) === 4 ? 'DeviceCMYK' : (($info['channels'] ?? 3) === 1 ? 'DeviceGray' : 'DeviceRGB')];
        $this->cur .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n", $w, $h, $x, $y, count($this->images));
        return true;
    }

    /**
     * Logo (PNG, JPEG, GIF, WebP) über GD in JPEG mit weißem Hintergrund umwandeln.
     * Gibt [JPEG, Breite, Höhe] zurück oder null, wenn GD fehlt oder das Bild nicht lesbar ist.
     */
    public static function toJpeg(string $data): ?array
    {
        if (!function_exists('imagecreatefromstring')) return null;
        $im = @imagecreatefromstring($data);
        if (!$im) return null;
        $w = imagesx($im); $h = imagesy($im);
        $bg = imagecreatetruecolor($w, $h);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($bg, null, 90);
        $jpg = (string)ob_get_clean();
        imagedestroy($im); imagedestroy($bg);
        return $jpg !== '' ? [$jpg, $w, $h] : null;
    }

    /** Fließtext mit Umbruch ab der aktuellen Höhe; neue Seite bei Bedarf */
    public function paragraph(string $utf8, float $size = 10.5, bool $bold = false, float $after = 6, float $indent = 0, string $prefix = ''): void
    {
        $lh = $size * 1.4;
        $maxW = $this->w - 2 * $this->margin - $indent;
        foreach (explode("\n", $utf8) as $k => $part) {
            $lines = self::wrap($part, $maxW, $size, $bold);
            foreach ($lines as $i => $ln) {
                $this->ensure($lh);
                if ($i === 0 && $k === 0 && $prefix !== '') $this->text($this->margin + $indent - 10, $this->y - $size, $prefix, $size, $bold);
                $this->text($this->margin + $indent, $this->y - $size, $ln, $size, $bold);
                $this->y -= $lh;
            }
        }
        $this->y -= $after;
    }

    /** Platz für $need Punkte prüfen, sonst neue Seite */
    public function ensure(float $need): void
    {
        if ($this->y - $need < $this->margin + 18) $this->addPage();
    }

    /**
     * Tabelle: $cols = [['label' => …, 'w' => Anteil, 'align' => 'left'|'right'], …];
     * $rows: Listen von Zellen (Text) oder ['_group' => Überschrift] für eine Zwischenzeile.
     */
    public function table(array $cols, array $rows, float $size = 8.5): void
    {
        $x0 = $this->margin;
        $total = $this->w - 2 * $this->margin;
        $sum = array_sum(array_column($cols, 'w')) ?: 1;
        $ws = array_map(fn($c) => $total * $c['w'] / $sum, $cols);
        $pad = 4;
        $lh = $size * 1.35;
        $head = function (SimplePdf $p) use ($cols, $ws, $x0, $total, $size, $pad) {
            $p->rect($x0, $p->y - $size - 6, $total, $size + 8, [235, 239, 244]);
            $x = $x0;
            foreach ($cols as $i => $c) {
                $label = self::fit($c['label'], $ws[$i] - 2 * $pad, $size, true);
                $p->text(($c['align'] ?? 'left') === 'right' ? $x + $ws[$i] - $pad : $x + $pad, $p->y - $size - 1, $label, $size, true, [60, 70, 85], $c['align'] ?? 'left');
                $x += $ws[$i];
            }
            $p->y -= $size + 10;
        };
        $prevHook = $this->onNewPage;
        $this->onNewPage = $head;
        $this->ensure(3 * $lh + 20);
        $head($this);
        foreach ($rows as $r) {
            if (isset($r['_group'])) {
                $this->ensure($lh * 2 + 8);
                $this->y -= 4;
                $this->text($x0, $this->y - $size, $r['_group'], $size + 1, true, [31, 95, 168]);
                $this->y -= $lh + 4;
                continue;
            }
            $cells = [];
            $height = 1;
            foreach ($cols as $i => $c) {
                $cells[$i] = ($c['wrap'] ?? false) ? self::wrap((string)($r[$i] ?? ''), $ws[$i] - 2 * $pad, $size) : [self::fit((string)($r[$i] ?? ''), $ws[$i] - 2 * $pad, $size)];
                $height = max($height, count($cells[$i]));
            }
            $this->ensure($height * $lh + 4);
            $x = $x0;
            foreach ($cols as $i => $c) {
                foreach ($cells[$i] as $k => $txt) {
                    $this->text(($c['align'] ?? 'left') === 'right' ? $x + $ws[$i] - $pad : $x + $pad, $this->y - $size - $k * $lh, $txt, $size, false, [20, 28, 40], $c['align'] ?? 'left');
                }
                $x += $ws[$i];
            }
            $this->y -= $height * $lh + 3;
            $this->line($x0, $this->y + 1, $x0 + $total, $this->y + 1, 0.4);
        }
        $this->onNewPage = $prevHook;
    }

    /** Fertiges PDF als Zeichenkette */
    public function output(string $title = ''): string
    {
        $pages = $this->pages;
        $pages[] = $this->cur;
        $n = count($pages);
        // Fußzeile mit Seitenzahl
        foreach ($pages as $i => &$c) {
            $foot = sprintf("BT 0.45 0.5 0.56 rg /F1 7.5 Tf %.2F %.2F Td (%s) Tj ET\n", $this->margin, 22, self::esc($this->footerLeft));
            $pageTxt = self::enc((string)($i + 1) . ' / ' . $n);
            $pw = 0;
            for ($k = 0; $k < strlen($pageTxt); $k++) $pw += self::W[$pageTxt[$k]] ?? 556;
            $foot .= sprintf("BT 0.45 0.5 0.56 rg /F1 7.5 Tf %.2F %.2F Td (%s) Tj ET\n", $this->w - $this->margin - $pw * 7.5 / 1000, 22, $pageTxt);
            $c .= $foot;
        }
        unset($c);
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $next = 5;
        $xobj = '';
        foreach ($this->images as $i => [$jpg, $iw, $ih, $cs]) {
            $objs[$next] = '<< /Type /XObject /Subtype /Image /Width ' . $iw . ' /Height ' . $ih . ' /ColorSpace /' . $cs
                . ($cs === 'DeviceCMYK' ? ' /Decode [1 0 1 0 1 0 1 0]' : '') . ' /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpg) . " >>\nstream\n" . $jpg . "\nendstream";
            $xobj .= '/Im' . ($i + 1) . ' ' . $next . ' 0 R ';
            $next++;
        }
        $res = '/Font << /F1 3 0 R /F2 4 0 R >>' . ($xobj !== '' ? ' /XObject << ' . $xobj . '>>' : '');
        foreach ($pages as $content) {
            $data = gzcompress($content, 6);
            $objs[$next] = '<< /Length ' . strlen($data) . " /Filter /FlateDecode >>\nstream\n" . $data . "\nendstream";
            $objs[$next + 1] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << %s >> /Contents %d 0 R >>', $this->w, $this->h, $res, $next);
            $kids[] = ($next + 1) . ' 0 R';
            $next += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[$next] = '<< /Title (' . self::esc($title) . ') /Producer (Kundenportal) /CreationDate (D:' . date('YmdHis') . ') >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($next + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $next; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $pdf .= 'trailer << /Size ' . ($next + 1) . ' /Root 1 0 R /Info ' . $next . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }
}
