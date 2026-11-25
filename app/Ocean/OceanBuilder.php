<?php

namespace App\Ocean;

/**
 * Constrói o oceano a partir dos polígonos do Natural Earth:
 *
 *  - coast.bin: campo de distância até a costa, 1440×720 uint8 (norte→sul, oeste→leste).
 *    128 é a linha da costa; cada 16 unidades valem um grau mar adentro (ou terra adentro).
 *  - currents.bin: u,v em mm/s, 360×180 pares int16 little-endian (sul→norte, oeste→leste),
 *    solução da equação de Stommel  r∇²ψ + β∂ψ/∂x = rot τ  com o contorno real dos continentes.
 */
final class OceanBuilder
{
    public const COAST_W = 1440;

    public const COAST_H = 720;

    public const FIELD_W = 360;

    public const FIELD_H = 180;

    private const FINE_W = 2880;

    private const FINE_H = 1440;

    private const EARTH_RADIUS_M = 6.371e6;

    private const OMEGA = 7.2921e-5;

    /** Atrito de fundo: a largura das correntes de borda oeste é r/β (~150 km a 30°). */
    private const DRAG = 3.0e-6;

    /** @return array<int, string> linhas norte→sul; '1' é terra */
    public function rasterize(array $geojson): array
    {
        $crossings = array_fill(0, self::FINE_H, []);
        $step = 180.0 / self::FINE_H;

        foreach ($geojson['features'] as $feature) {
            $geometry = $feature['geometry'];
            $polygons = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];

            foreach ($polygons as $rings) {
                foreach ($rings as $ring) {
                    for ($k = 1, $n = count($ring); $k < $n; $k++) {
                        [$x1, $y1] = $ring[$k - 1];
                        [$x2, $y2] = $ring[$k];
                        if ($y1 == $y2) {
                            continue;
                        }
                        $top = max($y1, $y2);
                        $bottom = min($y1, $y2);
                        $slope = ($x2 - $x1) / ($y2 - $y1);
                        $from = max(0, (int) ceil((90.0 - $top) / $step - 0.5));
                        $to = min(self::FINE_H - 1, (int) ceil((90.0 - $bottom) / $step - 0.5) - 1);
                        for ($j = $from; $j <= $to; $j++) {
                            $lat = 90.0 - ($j + 0.5) * $step;
                            $crossings[$j][] = $x1 + ($lat - $y1) * $slope;
                        }
                    }
                }
            }
        }

        $rows = [];
        foreach ($crossings as $xs) {
            $row = str_repeat('0', self::FINE_W);
            sort($xs);
            for ($k = 0; $k + 1 < count($xs); $k += 2) {
                $a = (int) ceil(($xs[$k] + 180.0) / $step - 0.5);
                $b = (int) ceil(($xs[$k + 1] + 180.0) / $step - 0.5);
                $a = max(0, $a);
                $length = min(self::FINE_W, $b) - $a;
                if ($length > 0) {
                    $row = substr_replace($row, str_repeat('1', $length), $a, $length);
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<int, string> $mask */
    public function coastDistance(array $mask): string
    {
        $w = self::FINE_W;
        $h = self::FINE_H;
        $step = 180.0 / $h;
        $far = 1.0e3;
        $d = array_fill(0, $w * $h, $far);

        for ($j = 0; $j < $h; $j++) {
            $row = $mask[$j];
            $north = $mask[max(0, $j - 1)];
            $south = $mask[min($h - 1, $j + 1)];
            for ($i = 0; $i < $w; $i++) {
                $c = $row[$i];
                if ($c !== $row[($i + 1) % $w] || $c !== $row[($i + $w - 1) % $w] || $c !== $north[$i] || $c !== $south[$i]) {
                    $d[$j * $w + $i] = $step * 0.5;
                }
            }
        }

        for ($pass = 0; $pass < 2; $pass++) {
            for ($j = 0; $j < $h; $j++) {
                $dx = $step * max(0.02, cos(deg2rad(90.0 - ($j + 0.5) * $step)));
                $dg = hypot($dx, $step);
                $base = $j * $w;
                for ($i = 0; $i < $w; $i++) {
                    $west = $i === 0 ? $w - 1 : $i - 1;
                    $best = min($d[$base + $i], $d[$base + $west] + $dx);
                    if ($j > 0) {
                        $up = $base - $w;
                        $east = $i === $w - 1 ? 0 : $i + 1;
                        $best = min($best, $d[$up + $i] + $step, $d[$up + $west] + $dg, $d[$up + $east] + $dg);
                    }
                    $d[$base + $i] = $best;
                }
            }
            for ($j = $h - 1; $j >= 0; $j--) {
                $dx = $step * max(0.02, cos(deg2rad(90.0 - ($j + 0.5) * $step)));
                $dg = hypot($dx, $step);
                $base = $j * $w;
                for ($i = $w - 1; $i >= 0; $i--) {
                    $east = $i === $w - 1 ? 0 : $i + 1;
                    $best = min($d[$base + $i], $d[$base + $east] + $dx);
                    if ($j < $h - 1) {
                        $down = $base + $w;
                        $west = $i === 0 ? $w - 1 : $i - 1;
                        $best = min($best, $d[$down + $i] + $step, $d[$down + $west] + $dg, $d[$down + $east] + $dg);
                    }
                    $d[$base + $i] = $best;
                }
            }
        }

        $out = '';
        for ($j = 0; $j < self::COAST_H; $j++) {
            $line = '';
            for ($i = 0; $i < self::COAST_W; $i++) {
                $sum = 0.0;
                foreach ([0, 1] as $dj) {
                    foreach ([0, 1] as $di) {
                        $y = $j * 2 + $dj;
                        $x = $i * 2 + $di;
                        $sum += ($mask[$y][$x] === '1' ? -1.0 : 1.0) * $d[$y * $w + $x];
                    }
                }
                $line .= chr((int) max(0, min(255, round(128 + ($sum / 4) * 16))));
            }
            $out .= $line;
        }

        return $out;
    }

    /** @param array<int, string> $mask */
    public function currents(array $mask): string
    {
        $w = self::FIELD_W;
        $h = self::FIELD_H;
        $sea = $this->seaCells($mask);

        $wind = $this->solve($sea, withWind: true, antarctica: 0.0);
        $circumpolar = $this->solve($sea, withWind: false, antarctica: 1.0);

        // ψ do vento em unidades livres: escala para que as correntes de borda cheguem a ~1 m/s.
        [$u, $v] = $this->velocity($wind, $sea);
        $speeds = [];
        foreach ($u as $k => $value) {
            if ($sea[$k]) {
                $speeds[] = hypot($value, $v[$k]);
            }
        }
        sort($speeds);
        $scale = 0.9 / $speeds[(int) (count($speeds) * 0.99)];

        $psi = [];
        foreach ($wind as $k => $value) {
            $psi[$k] = $value * $scale + $circumpolar[$k] * 3.2e5;
        }
        [$u, $v] = $this->velocity($psi, $sea);

        $out = '';
        for ($k = 0; $k < $w * $h; $k++) {
            $cap = 1.6 / max(1.6, hypot($u[$k], $v[$k]));
            $out .= pack('v2', ((int) round($u[$k] * $cap * 1000)) & 0xFFFF, ((int) round($v[$k] * $cap * 1000)) & 0xFFFF);
        }

        return $out;
    }

    /** @return array<int, bool> sul→norte */
    private function seaCells(array $mask): array
    {
        $sea = [];
        for ($j = 0; $j < self::FIELD_H; $j++) {
            for ($i = 0; $i < self::FIELD_W; $i++) {
                $land = 0;
                $top = (self::FIELD_H - 1 - $j) * 8;
                for ($y = $top; $y < $top + 8; $y++) {
                    $land += substr_count($mask[$y], '1', $i * 8, 8);
                }
                $sea[] = $land < 32 && $j < self::FIELD_H - 3;
            }
        }

        return $sea;
    }

    /** @return array<int, float> função de corrente ψ, por Gauss-Seidel varrendo de leste para oeste */
    private function solve(array $sea, bool $withWind, float $antarctica): array
    {
        $w = self::FIELD_W;
        $h = self::FIELD_H;
        $a = self::EARTH_RADIUS_M;
        $dl = deg2rad(1.0);
        $psi = array_fill(0, $w * $h, 0.0);
        $cells = [];

        for ($j = 0; $j < $h; $j++) {
            $lat = -89.5 + $j;
            $phi = deg2rad($lat);
            $cos = max(0.05, cos($phi));
            $dx = $a * $cos * $dl;
            $dy = $a * $dl;
            $beta = 2 * self::OMEGA * $cos / $a;
            $tan = tan($phi) / (2 * $a * $dy);
            $curl = 0.0;
            if ($withWind) {
                $cn = cos(deg2rad($lat + 0.5));
                $cs = cos(deg2rad($lat - 0.5));
                $curl = -(Wind::stress($lat + 0.5) * $cn - Wind::stress($lat - 0.5) * $cs) / ($dy * $cos) / 1.0e6;
            }
            $east = self::DRAG / $dx ** 2 + $beta / $dx;
            $west = self::DRAG / $dx ** 2;
            $north = self::DRAG * (1 / $dy ** 2 - $tan);
            $south = self::DRAG * (1 / $dy ** 2 + $tan);
            $diagonal = $east + $west + $north + $south;

            for ($i = $w - 1; $i >= 0; $i--) {
                $k = $j * $w + $i;
                if (! $sea[$k]) {
                    $psi[$k] = $lat < -60 ? $antarctica : 0.0;

                    continue;
                }
                $cells[] = [
                    $k, $j * $w + ($i + 1) % $w, $j * $w + ($i + $w - 1) % $w, min($h - 1, $j + 1) * $w + $i, max(0, $j - 1) * $w + $i,
                    $east / $diagonal, $west / $diagonal, $north / $diagonal, $south / $diagonal, -$curl / $diagonal,
                ];
            }
        }

        for ($sweep = 0; $sweep < 1200; $sweep++) {
            $change = 0.0;
            $peak = 1.0e-12;
            foreach ($cells as [$k, $e, $o, $n, $s, $ce, $co, $cn, $cs, $f]) {
                $next = $ce * $psi[$e] + $co * $psi[$o] + $cn * $psi[$n] + $cs * $psi[$s] + $f;
                $change = max($change, abs($next - $psi[$k]));
                $peak = max($peak, abs($next));
                $psi[$k] = $next;
            }
            if ($change / $peak < 1.0e-6) {
                break;
            }
        }

        return $psi;
    }

    /** @return array{0: array<int, float>, 1: array<int, float>} */
    private function velocity(array $psi, array $sea): array
    {
        $w = self::FIELD_W;
        $h = self::FIELD_H;
        $dy = self::EARTH_RADIUS_M * deg2rad(1.0);
        $u = $v = array_fill(0, $w * $h, 0.0);

        for ($j = 1; $j < $h - 1; $j++) {
            $dx = $dy * max(0.05, cos(deg2rad(-89.5 + $j)));
            for ($i = 0; $i < $w; $i++) {
                $k = $j * $w + $i;
                if ($sea[$k]) {
                    $u[$k] = -($psi[$k + $w] - $psi[$k - $w]) / (2 * $dy);
                    $v[$k] = ($psi[$j * $w + ($i + 1) % $w] - $psi[$j * $w + ($i + $w - 1) % $w]) / (2 * $dx);
                }
            }
        }

        return [$u, $v];
    }
}
