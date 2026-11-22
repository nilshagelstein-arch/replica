<?php
/**
 * Minimaler QR-Code-Generator (Byte-Modus, Fehlerkorrektur M) mit SVG-Ausgabe.
 * Damit wird der 2FA-Schlüssel als QR-Code angezeigt – ohne externe Dienste,
 * damit das Geheimnis den Server nie verlässt.
 * Algorithmus nach ISO/IEC 18004 (angelehnt an Project Nayuki, MIT-Lizenz).
 */

final class QrCodeM
{
    private const ECC_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28];
    private const NUM_BLOCKS   = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49];

    private int $version;
    private int $size;
    private array $modules = [];
    private array $isFunction = [];

    public static function svg(string $text, int $pixelSize = 220): string
    {
        $qr = new self($text);
        return $qr->toSvg($pixelSize);
    }

    public function __construct(string $text)
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $len = count($bytes);
        for ($ver = 1; $ver <= 40; $ver++) {
            $ccBits = $ver <= 9 ? 8 : 16;
            $capBits = self::numDataCodewords($ver) * 8;
            if (4 + $ccBits + 8 * $len <= $capBits) break;
        }
        if ($ver > 40) throw new RuntimeException(t('Text zu lang für QR-Code'));
        $this->version = $ver;
        $this->size = $ver * 4 + 17;

        // Bitstrom aufbauen
        $bits = [];
        $append = function (int $val, int $n) use (&$bits) {
            for ($i = $n - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1;
        };
        $append(0b0100, 4);
        $append($len, $ver <= 9 ? 8 : 16);
        foreach ($bytes as $b) $append($b, 8);
        $capBits = self::numDataCodewords($ver) * 8;
        $append(0, min(4, $capBits - count($bits)));
        $append(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capBits; $pad ^= 0xEC ^ 0x11) $append($pad, 8);

        $data = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $v = 0;
            for ($j = 0; $j < 8; $j++) $v = ($v << 1) | $bits[$i + $j];
            $data[] = $v;
        }

        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = $this->modules;
        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addEccAndInterleave($data));

        // beste Maske wählen
        $best = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $this->applyMask($m);
            $this->drawFormatBits($m);
            $p = $this->penalty();
            if ($p < $bestPenalty) { $best = $m; $bestPenalty = $p; }
            $this->applyMask($m); // rückgängig (XOR)
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    private static function numRawDataModules(int $ver): int
    {
        $r = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $na = intdiv($ver, 7) + 2;
            $r -= (25 * $na - 10) * $na - 55;
            if ($ver >= 7) $r -= 36;
        }
        return $r;
    }

    private static function numDataCodewords(int $ver): int
    {
        return intdiv(self::numRawDataModules($ver), 8) - self::ECC_PER_BLOCK[$ver] * self::NUM_BLOCKS[$ver];
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $s = $this->size;
        for ($i = 0; $i < $s; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $d = max(abs($dx), abs($dy));
                    $xx = $cx + $dx; $yy = $cy + $dy;
                    if ($xx >= 0 && $xx < $s && $yy >= 0 && $yy < $s) $this->set($xx, $yy, $d !== 2 && $d !== 4);
                }
            }
        }
        $pos = $this->alignmentPositions();
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) continue;
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0);
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            $bits = ($this->version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $s - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    private function alignmentPositions(): array
    {
        $ver = $this->version;
        if ($ver === 1) return [];
        $na = intdiv($ver, 7) + 2;
        $step = $ver === 32 ? 26 : (int)ceil(($ver * 4 + 4) / ($na * 2 - 2)) * 2;
        $res = [6];
        for ($pos = $this->size - 7; count($res) < $na; $pos -= $step) array_splice($res, 1, 0, [$pos]);
        return $res;
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (0 << 3) | $mask; // Level M = 0b00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $g = fn(int $i) => (($bits >> $i) & 1) === 1;
        $s = $this->size;
        for ($i = 0; $i <= 5; $i++) $this->set(8, $i, $g($i));
        $this->set(8, 7, $g(6));
        $this->set(8, 8, $g(7));
        $this->set(7, 8, $g(8));
        for ($i = 9; $i < 15; $i++) $this->set(14 - $i, 8, $g($i));
        for ($i = 0; $i < 8; $i++) $this->set($s - 1 - $i, 8, $g($i));
        for ($i = 8; $i < 15; $i++) $this->set(8, $s - 15 + $i, $g($i));
        $this->set(8, $s - 8, true);
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function rsDivisor(int $degree): array
    {
        $r = array_fill(0, $degree, 0);
        $r[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $r[$j] = self::gfMul($r[$j], $root);
                if ($j + 1 < $degree) $r[$j] ^= $r[$j + 1];
            }
            $root = self::gfMul($root, 0x02);
        }
        return $r;
    }

    private static function rsRemainder(array $data, array $div): array
    {
        $r = array_fill(0, count($div), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($div as $i => $d) $r[$i] ^= self::gfMul($d, $factor);
        }
        return $r;
    }

    private function addEccAndInterleave(array $data): array
    {
        $ver = $this->version;
        $numBlocks = self::NUM_BLOCKS[$ver];
        $eccLen = self::ECC_PER_BLOCK[$ver];
        $raw = intdiv(self::numRawDataModules($ver), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        $div = self::rsDivisor($eccLen);
        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $len = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::rsRemainder($dat, $div);
            if ($i < $numShort) $dat[] = 0;
            $blocks[] = array_merge($dat, $ecc);
        }
        $res = [];
        $blen = count($blocks[0]);
        for ($i = 0; $i < $blen; $i++) {
            foreach ($blocks as $j => $blk) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) $res[] = $blk[$i];
            }
        }
        return $res;
    }

    private function drawCodewords(array $data): void
    {
        $s = $this->size;
        $total = count($data) * 8;
        $i = 0;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $s - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $m): void
    {
        $s = $this->size;
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                if ($this->isFunction[$y][$x]) continue;
                switch ($m) {
                    case 0: $inv = ($x + $y) % 2 === 0; break;
                    case 1: $inv = $y % 2 === 0; break;
                    case 2: $inv = $x % 3 === 0; break;
                    case 3: $inv = ($x + $y) % 3 === 0; break;
                    case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                    case 5: $inv = ($x * $y) % 2 + ($x * $y) % 3 === 0; break;
                    case 6: $inv = (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0; break;
                    default: $inv = ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0;
                }
                if ($inv) $this->modules[$y][$x] = !$this->modules[$y][$x];
            }
        }
    }

    /** vereinfachte Strafpunktberechnung (Läufe, 2×2-Blöcke, Hell/Dunkel-Verhältnis) */
    private function penalty(): int
    {
        $s = $this->size;
        $p = 0;
        $dark = 0;
        for ($a = 0; $a < $s; $a++) {
            $runR = 1; $runC = 1;
            for ($b = 1; $b < $s; $b++) {
                if ($this->modules[$a][$b] === $this->modules[$a][$b - 1]) { $runR++; } else { if ($runR >= 5) $p += $runR - 2; $runR = 1; }
                if ($this->modules[$b][$a] === $this->modules[$b - 1][$a]) { $runC++; } else { if ($runC >= 5) $p += $runC - 2; $runC = 1; }
            }
            if ($runR >= 5) $p += $runR - 2;
            if ($runC >= 5) $p += $runC - 2;
        }
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                if ($this->modules[$y][$x]) $dark++;
                if ($x < $s - 1 && $y < $s - 1) {
                    $c = $this->modules[$y][$x];
                    if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) $p += 3;
                }
            }
        }
        // finderähnliche Muster (1011101 mit 4 hellen Modulen davor/danach)
        $pA = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $pB = array_reverse($pA);
        for ($a = 0; $a < $s; $a++) {
            for ($b = 0; $b + 11 <= $s; $b++) {
                $mr = $mc = [true, true];
                for ($k = 0; $k < 11; $k++) {
                    $vr = $this->modules[$a][$b + $k] ? 1 : 0;
                    $vc = $this->modules[$b + $k][$a] ? 1 : 0;
                    if ($vr !== $pA[$k]) $mr[0] = false;
                    if ($vr !== $pB[$k]) $mr[1] = false;
                    if ($vc !== $pA[$k]) $mc[0] = false;
                    if ($vc !== $pB[$k]) $mc[1] = false;
                }
                $p += 40 * (int)$mr[0] + 40 * (int)$mr[1] + 40 * (int)$mc[0] + 40 * (int)$mc[1];
            }
        }
        $total = $s * $s;
        $p += intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) * 10 - 10;
        return $p;
    }

    public function getModules(): array
    {
        return $this->modules;
    }

    public function toSvg(int $pixelSize): string
    {
        $border = 4;
        $dim = $this->size + 2 * $border;
        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . $pixelSize
            . '" height="' . $pixelSize . ('" shape-rendering="crispEdges" role="img" aria-label="' . e(t('QR-Code')) . '">')
            . '<rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }
}
