<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Traits\ImportHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use PDO;

class CustomersImportCommand extends Command
{
    // Rules:
    // 1. MySQL
    // 2. 256MB Memory Limit
    // 3. No Queue
    use ImportHelper;

    protected $signature = 'import:customers';

    protected $description = 'Import customers from CSV file.';

    public function handleImport($filePath): void
    {
        $this->info('Hello 👋');

        // Have fun running imports here
    }

}
