<?php

namespace App\Console\Commands;

use App\Ocean\OceanBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BuildOcean extends Command
{
    protected $signature = 'ocean:build';

    protected $description = 'Calcula o campo de costa e as correntes a partir do Natural Earth';

    public function handle(OceanBuilder $builder): int
    {
        ini_set('memory_limit', '1G');
        $path = config('ocean.data_path');
        File::ensureDirectoryExists($path);

        $this->components->task('Rasterizando os continentes', function () use ($builder, &$mask) {
            $mask = $builder->rasterize(json_decode(File::get(resource_path('geo/ne_50m_land.json')), true));
        });
        $this->components->task('Medindo a distância até a costa', fn () => File::put("$path/coast.bin", $builder->coastDistance($mask)) !== false);
        $this->components->task('Resolvendo a equação de Stommel', fn () => File::put("$path/currents.bin", $builder->currents($mask)) !== false);

        return self::SUCCESS;
    }
}
