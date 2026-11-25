<?php

namespace App\Console\Commands;

use App\Models\Bottle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MongoDB\Client;
use Throwable;

class PrepareOcean extends Command
{
    protected $signature = 'ocean:prepare';

    protected $description = 'Deixa tudo pronto: replica set, oceano, coleções, atlas e cartas iniciais';

    public function handle(): int
    {
        $this->ensureReplicaSet();

        if (! is_file(config('ocean.data_path').'/currents.bin')) {
            $this->call('ocean:build');
        }
        $this->call('migrate', ['--force' => true]);
        if (DB::connection('mongodb')->getDatabase()->selectCollection('seas')->countDocuments() === 0) {
            $this->call('ocean:atlas');
        }
        if (Bottle::query()->count() === 0) {
            $this->call('db:seed', ['--force' => true]);
        }

        return self::SUCCESS;
    }

    /** Change streams exigem replica set; um nó só basta. */
    private function ensureReplicaSet(): void
    {
        $uri = config('database.connections.mongodb.dsn');
        $host = parse_url($uri, PHP_URL_HOST).':'.(parse_url($uri, PHP_URL_PORT) ?? 27017);
        $admin = (new Client("mongodb://$host/?directConnection=true"))->selectDatabase('admin');
        $hello = fn () => $admin->command(['hello' => 1])->toArray()[0];

        if (! isset($hello()['setName'])) {
            try {
                $admin->command(['replSetInitiate' => ['_id' => 'deriva', 'members' => [['_id' => 0, 'host' => $host]]]]);
            } catch (Throwable $e) {
                $this->components->warn('Não consegui iniciar o replica set: '.$e->getMessage());

                return;
            }
        }
        for ($k = 0; $k < 40 && ! ($hello()['isWritablePrimary'] ?? false); $k++) {
            usleep(250_000);
        }
    }
}
