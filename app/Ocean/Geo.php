<?php

namespace App\Ocean;

final class Geo
{
    public const EARTH_RADIUS_KM = 6371.0;

    private const WINDS = ['N', 'NE', 'L', 'SE', 'S', 'SO', 'O', 'NO'];

    public static function wrap(float $lng): float
    {
        $lng = fmod($lng + 180.0, 360.0);

        return ($lng < 0 ? $lng + 360.0 : $lng) - 180.0;
    }

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /** Distância em km entre dois pontos GeoJSON. */
    public static function between(array $a, array $b): float
    {
        return self::distanceKm($a['coordinates'][1], $a['coordinates'][0], $b['coordinates'][1], $b['coordinates'][0]);
    }

    /** Rumo da corrente (para onde vai) em rosa dos ventos, com L = leste e O = oeste. */
    public static function compass(float $u, float $v): string
    {
        $degrees = fmod(rad2deg(atan2($u, $v)) + 360.0, 360.0);

        return self::WINDS[(int) round($degrees / 45) % 8];
    }

    /** @return array{type: string, coordinates: array{0: float, 1: float}} */
    public static function point(float $lat, float $lng): array
    {
        return ['type' => 'Point', 'coordinates' => [round(self::wrap($lng), 5), round($lat, 5)]];
    }
}
