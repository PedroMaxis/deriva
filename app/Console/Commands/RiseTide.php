<?php

namespace App\Console\Commands;

use App\Ocean\Tide;
use Illuminate\Console\Command;

class RiseTide extends Command
{
    protected $signature = 'ocean:tide {--watch : Continua subindo a maré até ser interrompido} {--every=5 : Segundos entre marés}';

    protected $description = 'Move as garrafas até o instante atual';

    public function handle(Tide $tide): int
    {
        do {
            $result = $tide->rise();
            if ($result['ashore'] > 0 || ! $this->option('watch')) {
                $this->components->info("{$result['moved']} garrafas moveram-se, {$result['ashore']} encalharam.");
            }
            if ($this->option('watch')) {
                sleep(max(1, (int) $this->option('every')));
            }
        } while ($this->option('watch'));

        return self::SUCCESS;
    }
}
