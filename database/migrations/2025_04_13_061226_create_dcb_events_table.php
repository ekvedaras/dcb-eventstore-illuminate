<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webmozart\Assert\Assert;

return new class extends Migration {
    public function up(): void
    {
        $tableNames = Arr::wrap(config('dcb_event_store.events_table_name'));

        foreach ($tableNames as $tableName) {
            Assert::stringNotEmpty($tableName);

            if (Schema::hasTable($tableName)) {
                return;
            }

            Schema::create($tableName, function (Blueprint $table): void {
                $table->increments('sequence_number');
                $table->string('type');
                $table->text('data');
                $table->jsonb('metadata')->nullable();
                $table->jsonb('tags');
                $table->dateTime('recorded_at');
            });

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                DB::statement("create index tags on {$tableName} using gin(tags jsonb_path_ops)");
            }
        }
    }

    public function down(): void
    {
        $tableNames = Arr::wrap(config('dcb_event_store.events_table_name'));

        foreach ($tableNames as $tableName) {
            Assert::stringNotEmpty($tableNames);
            Schema::dropIfExists($tableName);
        }
    }
};
