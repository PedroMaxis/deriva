<?php

namespace App\Ocean;

use Illuminate\Support\Facades\DB;
use MongoDB\Collection;

/** Nomes do mundo: em que mar estou, em que costa encalhei. */
final class Atlas
{
    /** O menor polígono que contém o ponto vence: Mar do Caribe antes de Atlântico Norte. */
    public function sea(float $lat, float $lng): string
    {
        $sea = $this->collection('seas')->findOne(
            ['shape' => ['$geoIntersects' => ['$geometry' => Geo::point($lat, $lng)]]],
            ['sort' => ['area' => 1], 'projection' => ['name' => 1]],
        );

        return $sea['name'] ?? ($lat < -55 ? 'Oceano Antártico' : 'Mar sem nome');
    }

    public function shore(float $lat, float $lng): ?string
    {
        $shore = $this->collection('shores')->findOne(
            ['position' => ['$nearSphere' => ['$geometry' => Geo::point($lat, $lng), '$maxDistance' => 600_000]]],
            ['projection' => ['name' => 1]],
        );

        return $shore['name'] ?? null;
    }

    private function collection(string $name): Collection
    {
        return DB::connection('mongodb')->getDatabase()->selectCollection($name);
    }
}
