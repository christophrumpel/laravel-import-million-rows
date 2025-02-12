<?php

namespace App\Traits;

use App\Models\Customer;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use PDO;
use PDOStatement;
use function Laravel\Prompts\select;

trait ImportHelper
{
    protected float $benchmarkStartTime;
    protected int $benchmarkStartMemory;
    protected int $startRowCount;
    protected int $startQueries;

    public function handle(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        Customer::truncate();
        $filePath = $this->selectFile();
        $this->startBenchmark();

        try {
            $this->handleImport($filePath);
        } catch(\Exception $e) {
            $this->error(get_class($e) . " " . Str::of($e->getMessage())->limit(100)->value());
        }

        $this->endBenchmark();
    }

    protected function selectFile(): string
    {
        $file = select(
            label: 'What file do you want to import?',
            options: ['CSV 100 Customers', 'CSV 1K Customers', 'CSV 10K Customers', 'CSV 100K Customers', 'CSV 1M Customers', 'CSV 2M Customers']
        );

        return match ($file) {
            'CSV 100 Customers' => base_path('customers-100.csv'),
            'CSV 1K Customers' => base_path('customers-1k.csv'),
            'CSV 10K Customers' => base_path('customers-10k.csv'),
            'CSV 100K Customers' => base_path('customers-100k.csv'),
            'CSV 1M Customers' => base_path('customers-1m.csv'),
            'CSV 2M Customers' => base_path('customers-2m.csv'),
        };
    }

    protected function startBenchmark(string $table = 'customers'): void
    {
        $this->startRowCount = DB::table($table)->count();
        $this->benchmarkStartTime = microtime(true);
        $this->benchmarkStartMemory = memory_get_usage();
        DB::enableQueryLog();
        $this->startQueries = DB::select("SHOW SESSION STATUS LIKE 'Questions'")[0]->Value;
    }

    protected function endBenchmark(string $table = 'customers'): void
    {
        $executionTime = microtime(true) - $this->benchmarkStartTime;
        $memoryUsage = round((memory_get_usage() - $this->benchmarkStartMemory) / 1024 / 1024, 2);
        $queriesCount = DB::select("SHOW SESSION STATUS LIKE 'Questions'")[0]->Value - $this->startQueries - 1; // Subtract the Questions query itself

        // Get row count after we've stopped tracking queries
        $rowDiff = DB::table($table)->count() - $this->startRowCount;

        $formattedTime = match (true) {
            $executionTime >= 60 => sprintf('%dm %ds', floor($executionTime / 60), $executionTime % 60),
            $executionTime >= 1 => round($executionTime, 2) . 's',
            default => round($executionTime * 1000) . 'ms',
        };

        $this->newLine();
        $this->line(sprintf(
            '⚡ <bg=bright-blue;fg=black> TIME: %s </> <bg=bright-green;fg=black> MEM: %sMB </> <bg=bright-yellow;fg=black> SQL: %s </> <bg=bright-magenta;fg=black> ROWS: %s </>',
            $formattedTime,
            $memoryUsage,
            number_format($queriesCount),
            number_format($rowDiff)
        ));
        $this->newLine();
    }

    private function import01BasicOneByOne(string $filePath): void
    {
        // Most basic approach - one query per record
        // 100 130ms / 0.35MB
        // 1K 549ms / 2MB
        // 10K 5.7s / 19MB
        // 100K memory issue (from mapping)
        // 1M memory issue (from file loading)
        // conclusion: works but slow and in-efficient
        collect(file($filePath))
            ->skip(1)
            ->map(fn($line) => str_getcsv($line))
            ->map(fn($row) => [
                'custom_id' => $row[0],
                'name' => $row[1],
                'email' => $row[2],
                'company' => $row[3],
                'city' => $row[4],
                'country' => $row[5],
                'birthday' => $row[6],
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->each(fn($customer) => Customer::create($customer));
    }

    private function import02CollectAndInsert(string $filePath): void
    {
        // Collect all and single insert
        // Shows prepared statement limit with large datasets
        // 100 16ms / 0.05MB
        // 1K 62ms / 0.57MB
        // 10K prepared statement issue
        // 100K memory issue
        // 1M memory issue
        // conclusion: prepared statement placeholder issue and memory again
        $now = now()->format('Y-m-d H:i:s');

        $allCustomers = collect(file($filePath))
            ->skip(1)
            ->map(fn($line) => str_getcsv($line))
            ->map(fn($row) => [
                'custom_id' => $row[0],
                'name' => $row[1],
                'email' => $row[2],
                'company' => $row[3],
                'city' => $row[4],
                'country' => $row[5],
                'birthday' => $row[6],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        Customer::insert($allCustomers->all());
    }

    private function import03CollectAndChunk(string $filePath): void
    {
        // Collect all but insert in chunks
        // Still has memory issues with large files
        // 100 15ms / 0.05MB
        // 1K 65ms / 0.57MB
        // 10K 246ms / 5,7MB
        // 100K 2.6s / 56.97MB
        // 1M memory issue (while file loading)
        // Conclusion super fast till 1M
        $now = now()->format('Y-m-d H:i:s');

        collect(file($filePath))
            ->skip(1)
            ->map(fn($line) => str_getcsv($line))
            ->map(fn($row) => [
                'custom_id' => $row[0],
                'name' => $row[1],
                'email' => $row[2],
                'company' => $row[3],
                'city' => $row[4],
                'country' => $row[5],
                'birthday' => $row[6],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->chunk(1000)
            ->each(fn($chunk) => Customer::insert($chunk->all()));
    }

    private function import04LazyCollection(string $filePath): void
    {
        // 100 66ms / 0.39MB
        // 1K 37ms / 1.47MB
        // 10K 3s / 12MB
        // 100K 39s / 120MB
        // 1M memory issue
        // 2M
        $now = now()->format('Y-m-d H:i:s');

        LazyCollection::make(function () use ($filePath) {
            $handle = fopen($filePath, 'r');
            fgets($handle); // skip header

            while (($line = fgets($handle)) !== false) {
                yield str_getcsv($line);
            }
            fclose($handle);
        })
            ->each(function ($row) use ($now) {
                // Directly insert each row
                Customer::insert([
                    'custom_id' => $row[0],
                    'name' => $row[1],
                    'email' => $row[2],
                    'company' => $row[3],
                    'city' => $row[4],
                    'country' => $row[5],
                    'birthday' => $row[6],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    private function import05LazyCollectionWithChunking(string $filePath): void
    {
        // Lazy loading with chunking
        // 100 16ms / 0.28MB
        // 1K 61ms / 0.8MB
        // 10K 275ms / 5.93MB
        // 100K 1.7s / 57MB
        // 1M memory issue if not tuned properly
        $now = now()->format('Y-m-d H:i:s');
        $chunkSize = 1000; // Define your chunk size

        LazyCollection::make(function () use ($filePath) {
            $handle = fopen($filePath, 'r');
            fgets($handle); // skip header

            while (($line = fgets($handle)) !== false) {
                yield str_getcsv($line);
            }
            fclose($handle);
        })
            ->map(fn($row) => [
                'custom_id' => $row[0],
                'name' => $row[1],
                'email' => $row[2],
                'company' => $row[3],
                'city' => $row[4],
                'country' => $row[5],
                'birthday' => $row[6],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->chunk($chunkSize)
            ->each(fn($chunk) => Customer::insert($chunk->all()));
    }

    private function import06ManualStreaming(string $filePath): void
    {
        // Read and insert in chunks
        // Better memory management
        // 100 13ms / 0.05MB
        // 1K 39ms / 0.57MB
        // 10K 224ms / 5.69MB
        // 100K 1.8s / 56MB
        // 1M memory issue

        $data = [];
        $handle = fopen($filePath, 'rb');
        fgetcsv($handle); // skip header
        $now = now()->format('Y-m-d H:i:s');

        while (($row = fgetcsv($handle)) !== false) {
            $data[] = [
                'custom_id' => $row[0],
                'name' => $row[1],
                'email' => $row[2],
                'company' => $row[3],
                'city' => $row[4],
                'country' => $row[5],
                'birthday' => $row[6],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($data) === 1000) {
                Customer::insert($data);
                $data = [];
            }
        }

        if (! empty($data)) {
            Customer::insert($data);
        }

        fclose($handle);
    }

    private function import07PDOPrepared(string $filePath): void
    {
        // Direct database connection with prepared statements
        // 100 41ms / 0 MB
        // 1K 237ms /
        // 10K 2.21s
        // 100K 25.27s
        // 1M 4m43s
        // 2M
        $now = now()->format('Y-m-d H:i:s');
        $handle = fopen($filePath, 'r');
        fgets($handle); // skip header

        try {
            $pdo = DB::connection()->getPdo();
            $stmt = $pdo->prepare("
            INSERT INTO customers (custom_id, name, email, company, city, country, birthday, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

            while (($row = fgetcsv($handle)) !== false) {
                $stmt->execute([
                    $row[0],
                    $row[1],
                    $row[2],
                    $row[3],
                    $row[4],
                    $row[5],
                    $row[6],
                    $now,
                    $now
                ]);
            }
        } finally {
            fclose($handle);
        }
    }

    private function import08PDOPreparedChunked(string $filePath): void
    {
        // Direct database connection with prepared statements
        // 100 12ms / 0.15MB
        // 1K 49ms / 0.74MB
        // 10K 222ms / 0.74MB
        // 100K 1.5s / 0.74MB
        // 1M 15.3s / 0.74MB
        // 2M 24s / 0.74MN
        $now = now()->format('Y-m-d H:i:s');
        $handle = fopen($filePath, 'r');
        fgetcsv($handle); // skip header
        $chunkSize = 500;
        $chunks = [];

        try {
            $stmt = $this->prepareChunkedStatement($chunkSize);

            while (($row = fgetcsv($handle)) !== false) {
                $chunks = array_merge($chunks, [
                    $row[0], $row[1], $row[2], $row[3], $row[4],
                    $row[5], $row[6], $now, $now
                ]);

                if (count($chunks) === $chunkSize * 9) {  // 9 columns
                    $stmt->execute($chunks);
                    $chunks = [];
                }
            }

            // Handle remaining records
            if (!empty($chunks)) {
                $remainingRows = count($chunks) / 9;
                $stmt = $this->prepareChunkedStatement($remainingRows);
                $stmt->execute($chunks);
            }
        } finally {
            fclose($handle);
        }
    }



    private function import09Concurrent(string $filePath): void
    {
        // 100 168ms
        // 1K 172ms
        // 10K 234ms
        // 100K 595ms
        // 1M 4.36s
        // 2M 8.8s
        $now = now()->format('Y-m-d H:i:s');
        $numberOfProcesses = 10;

        $tasks = [];
        for ($i = 0; $i < $numberOfProcesses; $i++) {
            $tasks[] = function () use ($filePath, $i, $numberOfProcesses, $now) {
                DB::reconnect();

                $handle = fopen($filePath, 'r');
                fgets($handle); // Skip header
                $currentLine = 0;
                $customers = [];

                while (($line = fgets($handle)) !== false) {
                    // Each process takes every Nth line
                    if ($currentLine++ % $numberOfProcesses !== $i) {
                        continue;
                    }

                    $row = str_getcsv($line);
                    $customers[] = [
                        'custom_id' => $row[0],
                        'name' => $row[1],
                        'email' => $row[2],
                        'company' => $row[3],
                        'city' => $row[4],
                        'country' => $row[5],
                        'birthday' => $row[6],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($customers) === 1000) {
                        DB::table('customers')->insert($customers);
                        $customers = [];
                    }
                }

                if (!empty($customers)) {
                    DB::table('customers')->insert($customers);
                }

                fclose($handle);
                return true;
            };
        }

        Concurrency::run($tasks);

    }

    private function import10LoadDataInfile(string $filePath): void
    {
        // MySQL specific, fastest approach
        // 100 10ms / 0MB
        // 1K 29ms / 0MB
        // 10K 115ms / 0MB
        // 100K 567ms / 0MB
        // 1M 5s / 0MB
        // 2M 11s / 0MB
        $pdo = DB::connection()->getPdo();
        $pdo->setAttribute(PDO::MYSQL_ATTR_LOCAL_INFILE, true);

        $filepath = str_replace('\\', '/', $filePath);

        $query = <<<SQL
    LOAD DATA LOCAL INFILE '$filepath'
    INTO TABLE customers
    FIELDS TERMINATED BY ','
    ENCLOSED BY '"'
    LINES TERMINATED BY '\n'
    IGNORE 1 LINES
    (@col1, @col2, @col3, @col4, @col5, @col6, @col7)
    SET
        custom_id = @col1,
        name = @col2,
        email = @col3,
        company = @col4,
        city = @col5,
        country = @col6,
        birthday = @col7,
        created_at = NOW(),
        updated_at = NOW()
    SQL;

        $pdo->exec($query);
    }

    private function prepareChunkedStatement($chunkSize): PDOStatement
    {
        $rowPlaceholders = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $placeholders = implode(',', array_fill(0, $chunkSize, $rowPlaceholders));

        return DB::connection()->getPdo()->prepare("
        INSERT INTO customers (custom_id, name, email, company, city, country, birthday, created_at, updated_at)
        VALUES {$placeholders}
    ");
    }
}
