<?php

namespace App\Console\Commands;

use App\Ocean\Ocean;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use MongoDB\Driver\Exception\BulkWriteException;

class BuildAtlas extends Command
{
    protected $signature = 'ocean:atlas';

    protected $description = 'Grava os nomes dos mares e das costas no MongoDB';

    private const SEAS = [
        'North Atlantic Ocean' => 'Atlântico Norte', 'South Atlantic Ocean' => 'Atlântico Sul',
        'North Pacific Ocean' => 'Pacífico Norte', 'South Pacific Ocean' => 'Pacífico Sul',
        'INDIAN OCEAN' => 'Oceano Índico', 'SOUTHERN OCEAN' => 'Oceano Antártico', 'Arctic Ocean' => 'Oceano Ártico',
        'Caribbean Sea' => 'Mar do Caribe', 'Greenland Sea' => 'Mar da Groenlândia', 'Amazon River' => 'Foz do Amazonas',
        'Gulf of Aden' => 'Golfo de Áden', 'Viscount Melville Sound' => 'Estreito de Melville',
    ];

    private const COUNTRIES = [
        'Gronelândia' => 'Groenlândia', 'Sara Ocidental' => 'Saara Ocidental', 'Madagáscar' => 'Madagascar', 'Irão' => 'Irã',
        'Iémen' => 'Iêmen', 'Quénia' => 'Quênia', 'Vietname' => 'Vietnã', 'Polónia' => 'Polônia',
        'Estónia' => 'Estônia', 'Letónia' => 'Letônia', 'Roménia' => 'Romênia', 'Eslovénia' => 'Eslovênia', 'Mónaco' => 'Mônaco',
        'Nova Caledónia' => 'Nova Caledônia',
        'Maurícia' => 'Maurício', 'Maurícias' => 'Maurício', 'Bahrein' => 'Barein',
        'República da Irlanda' => 'Irlanda', 'Terras Austrais e Antárticas Francesas' => 'Terras Austrais Francesas', 'Terras Austrais e Antárcticas Francesas' => 'Terras Austrais Francesas',
    ];

    public function handle(Ocean $ocean): int
    {
        ini_set('memory_limit', '1G');
        $db = DB::connection('mongodb')->getDatabase();

        $seas = $db->selectCollection('seas');
        $seas->deleteMany([]);
        $saved = $refused = 0;
        foreach ($this->features('ne_50m_geography_marine_polys') as $feature) {
            $p = $feature['properties'];
            $name = self::SEAS[$p['name_en'] ?? ''] ?? self::SEAS[$p['name'] ?? ''] ?? $p['name_pt'] ?? $p['name'];
            $polygons = $feature['geometry']['type'] === 'Polygon' ? [$feature['geometry']['coordinates']] : $feature['geometry']['coordinates'];
            foreach ($polygons as $rings) {
                $ring = $this->clean($rings[0]);
                if (count($ring) < 4) {
                    continue;
                }
                try {
                    $seas->insertOne([
                        'name' => mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1),
                        'area' => $this->area($ring),
                        'shape' => ['type' => 'Polygon', 'coordinates' => [$ring]],
                    ]);
                    $saved++;
                } catch (BulkWriteException) { // anel que se cruza: o S2 recusa
                    $refused++;
                }
            }
        }
        $this->components->info("$saved polígonos de mar gravados, $refused recusados.");

        $shores = [];
        foreach ($this->features('ne_50m_admin_0_countries') as $feature) {
            $name = $feature['properties']['NAME_PT'] ?? $feature['properties']['NAME'];
            $name = self::COUNTRIES[$name] ?? $name;
            $polygons = $feature['geometry']['type'] === 'Polygon' ? [$feature['geometry']['coordinates']] : $feature['geometry']['coordinates'];
            foreach ($polygons as $rings) {
                foreach ($rings[0] as $k => [$lng, $lat]) {
                    if ($k % 2 === 0 && abs($ocean->coastDistance($lat, $lng)) < 0.5) {
                        $shores[] = ['name' => $name, 'position' => ['type' => 'Point', 'coordinates' => [max(-180, min(180, $lng)), $lat]]];
                    }
                }
            }
        }
        $db->selectCollection('shores')->deleteMany([]);
        foreach (array_chunk($shores, 2000) as $chunk) {
            $db->selectCollection('shores')->insertMany($chunk);
        }
        $this->components->info(count($shores).' pontos de costa gravados.');

        return self::SUCCESS;
    }

    private function features(string $file): array
    {
        return json_decode(File::get(resource_path("geo/$file.json")), true)['features'];
    }

    private function clean(array $ring): array
    {
        $out = [];
        foreach ($ring as [$lng, $lat]) {
            $point = [round(max(-180, min(180, $lng)), 4), round(max(-89.9, min(89.9, $lat)), 4)];
            if ($out === [] || end($out) !== $point) {
                $out[] = $point;
            }
        }
        if ($out !== [] && end($out) !== $out[0]) {
            $out[] = $out[0];
        }

        return $out;
    }

    private function area(array $ring): float
    {
        $sum = 0.0;
        for ($k = 1; $k < count($ring); $k++) {
            $sum += ($ring[$k][0] - $ring[$k - 1][0]) * (sin(deg2rad($ring[$k][1])) + sin(deg2rad($ring[$k - 1][1])));
        }

        return abs($sum);
    }
}
