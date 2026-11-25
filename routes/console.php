<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('ocean:tide')->everyMinute()->withoutOverlapping(5);
