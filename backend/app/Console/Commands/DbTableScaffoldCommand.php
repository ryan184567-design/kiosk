<?php

/* *****************************************
File: DbTableScaffoldCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 18 第 1 點、18.2 第 10-12 項：依資料表產生 migration + seed 腳本。
***************************************** */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// 見 README 18 第 1 點、18.2 第 10-12 項：依資料表產生 migration + seed 腳本。
class DbTableScaffoldCommand extends Command
{
    protected $signature = 'db:table:scaffold
        {table : 要匯出的資料表名稱}
        {--only= : migration 或 seed，不帶則兩者都產生}
        {--connection= : 資料庫連線名稱，不帶則用預設連線}
        {--output-path=database/migrations : migration 輸出目錄，掃描 hq 表時請帶 database/hq/migrations}
        {--chunk=500 : seed 每批 INSERT 筆數}
        {--force : 檔案已存在時覆蓋}';

    protected $description = '依資料表產生 migration + seed 腳本骨架（見 README 18）';

    private const TYPE_MAP = [
        'tinyint' => 'tinyInteger',
        'smallint' => 'smallInteger',
        'mediumint' => 'integer',
        'int' => 'integer',
        'bigint' => 'bigInteger',
        'decimal' => 'decimal',
        'float' => 'float',
        'double' => 'double',
        'varchar' => 'string',
        'char' => 'char',
        'text' => 'text',
        'mediumtext' => 'mediumText',
        'longtext' => 'longText',
        'date' => 'date',
        'datetime' => 'dateTime',
        'timestamp' => 'timestamp',
        'time' => 'time',
        'json' => 'json',
        'enum' => 'enum',
    ];

    public function handle(): int
    {
        $table = $this->argument('table');
        $connection = $this->option('connection');
        $only = $this->option('only');

        $columns = $this->getColumns($table, $connection);

        if ($columns->isEmpty()) {
            $this->error("找不到資料表或資料表沒有欄位：{$table}");

            return self::FAILURE;
        }

        if ($only !== 'seed') {
            $this->writeMigration($table, $columns);
        }

        if ($only !== 'migration') {
            $this->writeSeed($table, $columns, $connection);
        }

        return self::SUCCESS;
    }

    private function getColumns(string $table, ?string $connection): Collection
    {
        $db = DB::connection($connection);
        $databaseName = $db->getDatabaseName();

        $rows = $db->select(
            'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
                    IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA, COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [$databaseName, $table]
        );

        return collect($rows);
    }

    private function writeMigration(string $table, Collection $columns): void
    {
        $fileName = date('Y_m_d_His')."_create_{$table}_table_from_scaffold.php";
        $outputPath = $this->option('output-path') ?: 'database/migrations';
        File::ensureDirectoryExists(base_path($outputPath));
        $path = base_path("{$outputPath}/{$fileName}");

        $lines = [];

        foreach ($columns as $col) {
            $lines[] = '            '.$this->columnDefinition($col).';';
        }

        $body = implode("\n", $lines);

        $stub = <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        // 由 db:table:scaffold 依現有資料表 `{$table}` 產生，型別為盡量比對結果，
        // 請人工核對索引/外鍵/預設值後再使用，見 README 13 節。
        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('{$table}_scaffold', function (Blueprint \$table) {
        {$body}
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$table}_scaffold');
            }
        };

        PHP;

        File::put($path, $stub);
        $this->info("已產生 migration：{$path}（資料表名稱刻意加上 _scaffold 後綴，確認欄位正確後請自行改名並移除此行提醒）");
    }

    private function columnDefinition(object $col): string
    {
        $name = $col->COLUMN_NAME;
        $method = self::TYPE_MAP[$col->DATA_TYPE] ?? 'string';

        $def = match ($method) {
            'string', 'char' => "\$table->{$method}('{$name}'".($col->CHARACTER_MAXIMUM_LENGTH ? ", {$col->CHARACTER_MAXIMUM_LENGTH}" : '').')',
            'decimal' => "\$table->decimal('{$name}', {$col->NUMERIC_PRECISION}, {$col->NUMERIC_SCALE})",
            'enum' => "\$table->string('{$name}') /* enum {$col->COLUMN_TYPE}，Blueprint enum() 需要人工填入選項 */",
            default => "\$table->{$method}('{$name}')",
        };

        if ($col->COLUMN_KEY === 'PRI' && $col->EXTRA === 'auto_increment') {
            return "\$table->id('{$name}')";
        }

        if ($col->IS_NULLABLE === 'YES') {
            $def .= '->nullable()';
        }

        return $def;
    }

    private function writeSeed(string $table, Collection $columns, ?string $connection): void
    {
        $className = Str::studly($table).'ScaffoldSeeder';
        $path = database_path("seeders/{$className}.php");

        if (File::exists($path) && ! $this->option('force')) {
            $this->error("seeder 已存在（加 --force 覆蓋）：{$path}");

            return;
        }

        $chunkSize = (int) $this->option('chunk');
        $rows = DB::connection($connection)->table($table)->get();
        $chunks = $rows->chunk($chunkSize);

        $inserts = $chunks->map(function ($chunk) {
            $data = $chunk->map(fn ($row) => (array) $row)->all();

            return '        DB::table(\''.'__TABLE__'.'\')->insert('.var_export($data, true).');';
        })->implode("\n\n");

        $stub = <<<PHP
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Console\Seeds\WithoutModelEvents;
        use Illuminate\Database\Seeder;
        use Illuminate\Support\Facades\DB;

        // 由 db:table:scaffold 依現有資料表 `{$table}` 的資料產生（{$rows->count()} 筆，
        // 每批 {$chunkSize} 筆 INSERT），見 README 13 節。重複執行前請確認是否會重複插入。
        class {$className} extends Seeder
        {
            use WithoutModelEvents;

            public function run(): void
            {
        {$inserts}
            }
        }

        PHP;

        $stub = str_replace('__TABLE__', $table, $stub);

        File::put($path, $stub);
        $this->info("已產生 seed：{$path}（共 {$rows->count()} 筆）");
    }
}
