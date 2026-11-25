<?php

namespace Tests;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abstract class OceanTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection('mongodb')->getDatabase();
        $this->assertSame('deriva_test', $db->getDatabaseName());

        static $ready = false;
        if (! $ready) {
            $db->drop();
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('ocean:atlas');
            $ready = true;
        }
        foreach (['bottles', 'drift_log'] as $name) {
            $db->selectCollection($name)->deleteMany([]);
        }
        $this->withoutMiddleware(ThrottleRequests::class);
    }
}
