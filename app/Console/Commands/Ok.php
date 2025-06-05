<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Ok extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ok';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run task as root';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        `chown www:www -R /mnt/www/t-api/storage/logs/*.log`;
    }
}
