<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Webmozart\Assert\Assert;

return new class extends Migration {
    public function up(): void
    {
        $tableNames = Arr::wrap(config('dcb_event_store.events_table_name'));

        foreach ($tableNames as $tableName) {
            Assert::stringNotEmpty($tableName);

            if (!Schema::hasTable($tableName)) {
                return;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['type'], 'idx_type');
                $table->index(['type', 'sequence_number'], 'idx_type_sequence_number');
            });
        }
    }

    public function down(): void
    {
        $tableNames = Arr::wrap(config('dcb_event_store.events_table_name'));

        foreach ($tableNames as $tableName) {
            Assert::stringNotEmpty($tableName);
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex('idx_type');
                $table->dropIndex('idx_type_sequence_number');
            });
        }
    }
};
