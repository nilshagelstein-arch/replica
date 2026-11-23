<?php
/**
 * Text aus PDF-Dateien lesen – ohne Fremdbibliothek und ohne Datenübertragung.
 *
 * Unterstützt die üblichen Formen von Programmen wie Word, LibreOffice, Browsern und Rechnungssystemen:
 * FlateDecode-Streams, Objekt-Streams (PDF 1.5+), Schriften mit ToUnicode-Tabelle (auch Identity-H) und
 * einfache Schriften mit WinAnsi-Kodierung. Gescannte PDFs (nur Bilder) liefern keinen Text.
 * Ist das Programm pdftotext auf dem Server vorhanden, wird es bevorzugt.
 */

declare(strict_types=1);

final class PdfText
{
    private string $raw;
    /** Objekte: Nummer => Inhalt (Wörterbuch bzw. Stream-Wörterbuch) */
    private array $objects = [];
    /** Stream-Daten (entpackt): Nummer => Daten */
    private array $streams = [];
    private array $fontCache = [];
    /** Obergrenzen gegen präparierte Dateien („Zip-Bomben“, riesige Tabellen) */
    private const MAX_STREAM = 8 * 1024 * 1024;   // je entpacktem Stream
    private const MAX_TOTAL = 24 * 1024 * 1024;    // alle Streams zusammen
    private const MAX_TEXT = 2 * 1024 * 1024;      // gelesener Text
    private const MAX_SECONDS = 15;
    private int $total = 0;
    private float $deadline = 0.0;

    public function __construct(string $raw)
    {
        $this->raw = $raw;
    }

    /** Text der ganzen Datei; '' wenn nichts lesbar ist */
    public static function extract(string $pdf): string
    {
        if (!str_starts_with(ltrim(substr($pdf, 0, 1024)), '%PDF')) return '';
        $viaTool = self::viaPdftotext($pdf);
        if ($viaTool !== null && trim($viaTool) !== '') return self::clean($viaTool);
        try {
            return self::clean((new self($pdf))->run());
        } catch (Throwable $ex) {
            return '';
        }
    }

    private static function viaPdftotext(string $pdf): ?string
    {
        if (!function_exists('shell_exec') || !function_exists('proc_open')) return null;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('proc_open', $disabled, true) || in_array('shell_exec', $disabled, true)) return null;
        $bin = @shell_exec('command -v pdftotext 2>/dev/null');
        $bin = is_string($bin) ? trim($bin) : '';
        if ($bin === '' || !is_executable($bin)) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'kpdf');
        if ($tmp === false) return null;
        file_put_contents($tmp, $pdf);
        $p = proc_open([$bin, '-enc', 'UTF-8', $tmp, '-'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $out = null;
        if (is_resource($p)) {
            // höchstens 15 Sekunden und 2 MB Text, danach abbrechen
            stream_set_blocking($pipes[1], false);
            $out = '';
            $end = microtime(true) + self::MAX_SECONDS;
            while (!feof($pipes[1]) && microtime(true) < $end && strlen($out) <= self::MAX_TEXT) {
                $r = [$pipes[1]]; $w = $e = null;
                if (@stream_select($r, $w, $e, 1) > 0) $out .= (string)fread($pipes[1], 65536);
            }
            $done = feof($pipes[1]);
            fclose($pipes[1]);
            if (!$done) proc_terminate($p, 9);
            proc_close($p);
            $out = substr($out, 0, self::MAX_TEXT);
        }
        @unlink($tmp);
        return is_string($out) ? $out : null;
    }

    private static function clean(string $s): string
    {
        $s = str_replace(["\r\n", "\r", "\f"], "\n", $s);
        $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s) ?? $s;
        $s = preg_replace("/ *\n */", "\n", $s) ?? $s;
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
        return trim($s);
    }

    // -----------------------------------------------------------------------
    // Objekte lesen
    // -----------------------------------------------------------------------

    private function run(): string
    {
        $this->deadline = microtime(true) + self::MAX_SECONDS;
        preg_match_all('/(\d+)\s+(\d+)\s+obj\b(.*?)\bendobj/s', $this->raw, $m, PREG_SET_ORDER);
        foreach ($m as $o) {
            $num = (int)$o[1];
            $body = $o[3];
            if (preg_match('/^(.*?)\bstream\r?\n(.*)$/s', $body, $sm)) {
                $dict = $sm[1];
                $data = preg_replace('/\r?\n?endstream\s*$/s', '', $sm[2]) ?? $sm[2];
                $this->objects[$num] = $dict;
                $this->streams[$num] = $this->decode($dict, $data);
            } else {
                $this->objects[$num] = $body;
            }
        }
        // Objekt-Streams (PDF 1.5+) auspacken
        foreach ($this->objects as $num => $dict) {
            if (!isset($this->streams[$num]) || !preg_match('#/Type\s*/ObjStm#', $dict)) continue;
            $n = (int)$this->dictInt($dict, 'N');
            $first = (int)$this->dictInt($dict, 'First');
            $data = $this->streams[$num];
            $head = preg_split('/\s+/', trim(substr($data, 0, $first))) ?: [];
            $pairs = [];
            for ($i = 0; $i + 1 < count($head) && count($pairs) < $n; $i += 2) $pairs[] = [(int)$head[$i], (int)$head[$i + 1]];
            foreach ($pairs as $k => [$on, $off]) {
                $end = isset($pairs[$k + 1]) ? $pairs[$k + 1][1] : strlen($data) - $first;
                if (!isset($this->objects[$on])) $this->objects[$on] = substr($data, $first + $off, $end - $off);
            }
        }
        $out = [];
        $len = 0;
        foreach ($this->pages() as $page) {
            if ($len > self::MAX_TEXT || microtime(true) > $this->deadline) break;
            $t = $this->pageText($page);
            $len += strlen($t);
            $out[] = $t;
        }
        if (!array_filter($out)) {
            // Notlösung: alle Inhalts-Streams in Dateireihenfolge
            foreach ($this->streams as $num => $data) {
                if (str_contains($data, 'BT') && (str_contains($data, 'Tj') || str_contains($data, 'TJ'))) $out[] = $this->contentText($data, []);
            }
        }
        return implode("\n\n", $out);
    }

    private function decode(string $dict, string $data): string
    {
        if (!preg_match('#/Filter\s*(\[[^\]]*\]|/\w+)#', $dict, $f)) return $data;
        $filters = preg_match_all('#/(\w+)#', $f[1], $fm) ? $fm[1] : [];
        foreach ($filters as $flt) {
            if ($flt === 'FlateDecode' || $flt === 'Fl') {
                $data = $this->inflate($data);
                if ($data === '') return '';
            } elseif ($flt === 'ASCIIHexDecode' || $flt === 'AHx') {
                $data = (string)@hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', rtrim($data, '>')) ?? '');
            } else {
                return ''; // Bilder (DCT, JBIG2 …) und Seltenes
            }
        }
        return $data;
    }

    /** Entpacken mit Obergrenze: liest stückweise und bricht beim Limit ab */
    private function inflate(string $data): string
    {
        $room = min(self::MAX_STREAM, self::MAX_TOTAL - $this->total);
        if ($room <= 0 || microtime(true) > $this->deadline) return '';
        $out = '';
        // üblich ist das zlib-Format; manche Programme schreiben rohes Deflate
        foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW] as $enc) {
            $ctx = @inflate_init($enc);
            if ($ctx === false) continue;
            $out = '';
            $ok = true;
            foreach (str_split($data, 65536) ?: [] as $chunk) {
                $part = @inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH);
                if ($part === false) { $ok = false; break; }
                $out .= $part;
                if (strlen($out) >= $room) { $out = substr($out, 0, $room); break; }
            }
            if ($ok || $out !== '') break;
        }
        $this->total += strlen($out);
        return $out;
    }

    private function dictInt(string $dict, string $key): ?int
    {
        return preg_match('#/' . $key . '\s+(\d+)#', $dict, $m) ? (int)$m[1] : null;
    }

    /** Wert eines Schlüssels: Wörterbuch << … >>, Feld [ … ] oder Verweis n 0 R (aufgelöst) */
    private function dictValue(string $dict, string $key): ?string
    {
        if (!preg_match('#/' . $key . '\b\s*#', $dict, $m, PREG_OFFSET_CAPTURE)) return null;
        $pos = $m[0][1] + strlen($m[0][0]);
        $rest = substr($dict, $pos);
        if (preg_match('/^(\d+)\s+\d+\s+R/', $rest, $r)) return $this->objects[(int)$r[1]] ?? null;
        if (str_starts_with($rest, '<<')) return $this->balanced($rest, '<<', '>>');
        if (str_starts_with($rest, '[')) return $this->balanced($rest, '[', ']');
        return preg_match('#^(/\w+|[\d.]+)#', $rest, $v) ? $v[1] : null;
    }

    private function balanced(string $s, string $open, string $close): string
    {
        $depth = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            if (substr($s, $i, strlen($open)) === $open) { $depth++; $i += strlen($open) - 1; }
            elseif (substr($s, $i, strlen($close)) === $close) { $depth--; $i += strlen($close) - 1; if ($depth === 0) return substr($s, 0, $i + 1); }
        }
        return $s;
    }

    /** Seiten in Reihenfolge: [['content' => [Objektnummern], 'res' => Ressourcen-Wörterbuch], …] */
    private function pages(): array
    {
        $root = null;
        foreach ($this->objects as $num => $d) if (preg_match('#/Type\s*/Catalog#', $d)) { $root = $d; break; }
        $pagesRoot = $root !== null && preg_match('#/Pages\s+(\d+)\s+\d+\s+R#', $root, $m) ? (int)$m[1] : null;
        $out = [];
        if ($pagesRoot !== null) $this->walk($pagesRoot, null, $out, 0);
        if (!$out) {
            foreach ($this->objects as $num => $d) if (preg_match('#/Type\s*/Page\b(?!s)#', $d)) $out[] = $this->pageInfo($d, null);
        }
        return $out;
    }

    private function walk(int $num, ?string $inheritedRes, array &$out, int $depth): void
    {
        if ($depth > 30 || !isset($this->objects[$num])) return;
        $d = $this->objects[$num];
        $res = $this->dictValue($d, 'Resources') ?? $inheritedRes;
        if (preg_match('#/Type\s*/Pages#', $d)) {
            $kids = $this->dictValue($d, 'Kids') ?? '';
            preg_match_all('/(\d+)\s+\d+\s+R/', $kids, $km);
            foreach ($km[1] as $k) $this->walk((int)$k, $res, $out, $depth + 1);
        } elseif (preg_match('#/Type\s*/Page\b#', $d)) {
            $out[] = $this->pageInfo($d, $res);
        }
    }

    private function pageInfo(string $d, ?string $res): array
    {
        $content = [];
        if (preg_match('#/Contents\s*\[([^\]]*)\]#', $d, $m)) { preg_match_all('/(\d+)\s+\d+\s+R/', $m[1], $cm); $content = array_map('intval', $cm[1]); }
        elseif (preg_match('#/Contents\s+(\d+)\s+\d+\s+R#', $d, $m)) {
            $n = (int)$m[1];
            // Contents kann auf ein Feld verweisen
            if (!isset($this->streams[$n]) && isset($this->objects[$n]) && preg_match_all('/(\d+)\s+\d+\s+R/', $this->objects[$n], $cm)) $content = array_map('intval', $cm[1]);
            else $content = [$n];
        }
        return ['content' => $content, 'res' => $this->dictValue($d, 'Resources') ?? $res ?? ''];
    }

    private function pageText(array $page): string
    {
        $fonts = [];
        $fontDict = $this->dictValue($page['res'], 'Font') ?? '';
        if (preg_match_all('#/([^\s/<>\[\]()]+)\s+(\d+)\s+\d+\s+R#', $fontDict, $fm, PREG_SET_ORDER)) {
            foreach ($fm as $f) $fonts[$f[1]] = $this->font((int)$f[2]);
        }
        $data = '';
        foreach ($page['content'] as $n) $data .= ($this->streams[$n] ?? '') . "\n";
        return $this->contentText($data, $fonts);
    }

    // -----------------------------------------------------------------------
    // Schriften und Zeichenzuordnung
    // -----------------------------------------------------------------------

    /** ['map' => [Code => Text], 'bytes' => 1|2] */
    private function font(int $num): array
    {
        if (isset($this->fontCache[$num])) return $this->fontCache[$num];
        $d = $this->objects[$num] ?? '';
        $font = ['map' => [], 'bytes' => preg_match('#/Subtype\s*/Type0#', $d) ? 2 : 1];
        if (preg_match('#/ToUnicode\s+(\d+)\s+\d+\s+R#', $d, $m) && isset($this->streams[(int)$m[1]])) {
            $cmap = $this->streams[(int)$m[1]];
            if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $cs)) $font['bytes'] = max(1, (int)(strlen($cs[1]) / 2));
            preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks);
            foreach ($blocks[1] as $b) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $b, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $p) $font['map'][hexdec($p[1])] = self::utf16hex($p[2]);
            }
            preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks);
            foreach ($blocks[1] as $b) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]+>|\[[^\]]*\])/', $b, $ranges, PREG_SET_ORDER);
                foreach ($ranges as $r) {
                    $lo = hexdec($r[1]); $hi = min(hexdec($r[2]), $lo + 65535);
                    if ($r[3][0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]+)>/', $r[3], $arr);
                        foreach ($arr[1] as $i => $h) $font['map'][$lo + $i] = self::utf16hex($h);
                    } else {
                        $start = hexdec(trim($r[3], '<>'));
                        if (count($font['map']) > 200000) break;
                        for ($c = $lo; $c <= $hi; $c++) { $ch = mb_chr($start + ($c - $lo), 'UTF-8'); $font['map'][$c] = $ch === false ? '' : $ch; }
                    }
                }
            }
        }
        return $this->fontCache[$num] = $font;
    }

    private static function utf16hex(string $h): string
    {
        if ($h === '') return '';
        $bin = (string)@hex2bin(strlen($h) % 2 ? '0' . $h : $h);
        if (strlen($bin) === 1) { $ch = mb_chr(ord($bin), 'UTF-8'); return $ch === false ? '' : $ch; }
        return (string)@mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
    }

    private function decodeString(string $bytes, ?array $font): string
    {
        if ($font && $font['map']) {
            $out = '';
            $w = $font['bytes'];
            for ($i = 0, $n = strlen($bytes); $i + $w <= $n; $i += $w) {
                $code = $w === 2 ? (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]) : ord($bytes[$i]);
                $out .= $font['map'][$code] ?? ($w === 1 ? self::cp1252(chr($code)) : '');
            }
            return $out;
        }
        if ($font && $font['bytes'] === 2) return ''; // Identity-H ohne Tabelle: nicht lesbar
        return self::cp1252($bytes);
    }

    private static function cp1252(string $s): string
    {
        $u = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
        return $u === false ? '' : $u;
    }

    // -----------------------------------------------------------------------
    // Inhalts-Stream auswerten
    // -----------------------------------------------------------------------

    private function contentText(string $data, array $fonts): string
    {
        $out = '';
        $font = null;
        $size = 10.0;
        $run = 0; // Zeichen seit der letzten Positionierung
        $lastY = null;
        $stack = [];
        $len = strlen($data);
        $i = 0;
        $newline = function () use (&$out) { if ($out !== '' && !str_ends_with($out, "\n")) $out .= "\n"; };
        $ops = 0;
        while ($i < $len) {
            if ((++$ops & 0xFFFF) === 0 && (microtime(true) > $this->deadline || strlen($out) > self::MAX_TEXT)) break;
            $c = $data[$i];
            if (ctype_space($c)) { $i++; continue; }
            if ($c === '%') { while ($i < $len && $data[$i] !== "\n" && $data[$i] !== "\r") $i++; continue; }
            if ($c === '(') { [$str, $i] = $this->literal($data, $i); $stack[] = ['s', $str]; continue; }
            if ($c === '<' && ($data[$i + 1] ?? '') !== '<') {
                $end = strpos($data, '>', $i);
                if ($end === false) break;
                $stack[] = ['s', (string)@hex2bin((strlen($h = preg_replace('/\s+/', '', substr($data, $i + 1, $end - $i - 1)) ?? '') % 2 ? $h . '0' : $h))];
                $i = $end + 1;
                continue;
            }
            if ($c === '[') { $stack[] = ['[', null]; $i++; continue; }
            if ($c === ']') {
                $arr = [];
                while ($stack && end($stack)[0] !== '[') array_unshift($arr, array_pop($stack));
                array_pop($stack);
                $stack[] = ['a', $arr];
                $i++;
                continue;
            }
            if ($c === '<' || $c === '>') { $i += 2; continue; } // Inline-Wörterbücher überspringen
            if ($c === '/') {
                $j = $i + 1;
                while ($j < $len && !ctype_space($data[$j]) && !str_contains('/[]()<>{}%', $data[$j])) $j++;
                $stack[] = ['n', substr($data, $i + 1, $j - $i - 1)];
                $i = $j;
                continue;
            }
            $j = $i;
            while ($j < $len && !ctype_space($data[$j]) && !str_contains('/[]()<>{}%', $data[$j])) $j++;
            $tok = substr($data, $i, max(1, $j - $i));
            $i = max($j, $i + 1);
            if (is_numeric($tok)) { $stack[] = ['#', (float)$tok]; continue; }
            switch ($tok) {
                case 'BI': // Inline-Bild überspringen
                    $e = strpos($data, 'EI', $i);
                    $i = $e === false ? $len : $e + 2;
                    break;
                case 'Tf':
                    $name = null;
                    foreach (array_reverse($stack) as $s) if ($s[0] === 'n') { $name = $s[1]; break; }
                    $font = $name !== null ? ($fonts[$name] ?? null) : null;
                    foreach (array_reverse($stack) as $s) if ($s[0] === '#') { $size = max(1.0, abs((float)$s[1])); break; }
                    break;
                case 'Td': case 'TD':
                    $nums = array_values(array_filter($stack, fn($s) => $s[0] === '#'));
                    $ty = $nums ? (float)end($nums)[1] : 0;
                    $tx = count($nums) >= 2 ? (float)$nums[count($nums) - 2][1] : 0.0;
                    if (abs($ty) > 0.5) $newline();
                    // Lücke größer als die geschätzte Breite des letzten Textstücks → Wortabstand
                    elseif ($tx > max($run * 0.62 * $size + 0.3 * $size, 1.05 * $size) && !str_ends_with($out, ' ') && !str_ends_with($out, "\n")) $out .= ' ';
                    $run = 0;
                    break;
                case 'Tm':
                    $nums = array_values(array_filter($stack, fn($s) => $s[0] === '#'));
                    $y = $nums ? (float)end($nums)[1] : null;
                    if ($y !== null && $lastY !== null && abs($y - $lastY) > 0.5) $newline();
                    elseif ($out !== '' && !str_ends_with($out, ' ') && !str_ends_with($out, "\n")) $out .= ' ';
                    $lastY = $y;
                    $run = 0;
                    break;
                case 'T*': case "'": case '"':
                    $newline();
                    if ($tok !== 'T*') foreach ($stack as $s) if ($s[0] === 's') $out .= $this->decodeString($s[1], $font);
                    break;
                case 'Tj':
                    foreach ($stack as $s) if ($s[0] === 's') { $t = $this->decodeString($s[1], $font); $out .= $t; $run += mb_strlen($t); }
                    break;
                case 'TJ':
                    foreach ($stack as $s) {
                        if ($s[0] !== 'a') continue;
                        foreach ($s[1] as $part) {
                            if ($part[0] === 's') { $t = $this->decodeString($part[1], $font); $out .= $t; $run += mb_strlen($t); }
                            elseif ($part[0] === '#' && $part[1] < -180 && !str_ends_with($out, ' ')) $out .= ' ';
                        }
                    }
                    break;
                case 'ET':
                    if (!str_ends_with($out, ' ') && !str_ends_with($out, "\n")) $out .= ' ';
                    break;
            }
            if (!in_array($tok, ['BT'], true)) $stack = [];
        }
        return $out;
    }

    /** Literal-String ( … ) mit Escapes und verschachtelten Klammern */
    private function literal(string $data, int $i): array
    {
        $depth = 0;
        $out = '';
        $len = strlen($data);
        for (; $i < $len; $i++) {
            $c = $data[$i];
            if ($c === '\\') {
                $n = $data[$i + 1] ?? '';
                $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
                if (isset($map[$n])) { $out .= $map[$n]; $i++; }
                elseif (ctype_digit($n)) {
                    $oct = '';
                    for ($k = 1; $k <= 3 && ctype_digit($data[$i + $k] ?? 'x') && ($data[$i + $k] ?? '9') < '8'; $k++) $oct .= $data[$i + $k];
                    $out .= chr(octdec($oct) & 0xFF);
                    $i += strlen($oct);
                } elseif ($n === "\r" || $n === "\n") { $i++; if ($n === "\r" && ($data[$i + 1] ?? '') === "\n") $i++; }
                else { $out .= $n; $i++; }
                continue;
            }
            if ($c === '(') { if ($depth++ > 0) $out .= $c; continue; }
            if ($c === ')') { if (--$depth === 0) return [$out, $i + 1]; $out .= $c; continue; }
            $out .= $c;
        }
        return [$out, $len];
    }
}
