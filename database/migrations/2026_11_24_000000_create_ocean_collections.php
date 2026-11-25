<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('mongodb')->getDatabase();

        // Trajetórias: time series com uma série por garrafa.
        $db->createCollection('drift_log', [
            'timeseries' => ['timeField' => 'ts', 'metaField' => 'bottle_id', 'granularity' => 'seconds'],
            'expireAfterSeconds' => config('ocean.drift_log_days') * 86400,
        ]);
        $db->selectCollection('drift_log')->createIndex(['bottle_id' => 1, 'ts' => 1]);

        $bottles = $db->selectCollection('bottles');
        $bottles->createIndex(['position' => '2dsphere', 'status' => 1]);
        $bottles->createIndex(['status' => 1, 'ashore_at' => -1]);
        $bottles->createIndex(['sender_key' => 1]);
        $bottles->createIndex(['opener_key' => 1], ['sparse' => true]);
        $bottles->createIndex(['parent_id' => 1], ['sparse' => true, 'unique' => true]);
        $bottles->createIndex(['days' => -1]);
        // TTL: só garrafas à deriva têm dissolves_at; ao encalhar o campo some e a carta fica a salvo.
        $bottles->createIndex(['dissolves_at' => 1], ['expireAfterSeconds' => 0]);

        $db->selectCollection('shores')->createIndex(['position' => '2dsphere']);
        $db->selectCollection('seas')->createIndex(['shape' => '2dsphere']);
    }

    public function down(): void
    {
        $db = DB::connection('mongodb')->getDatabase();
        foreach (['bottles', 'drift_log', 'shores', 'seas'] as $name) {
            $db->dropCollection($name);
        }
    }
};
