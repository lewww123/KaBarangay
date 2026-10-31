<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREFIX = 'perf_20261029_fk_';

    public function up(): void
    {
        foreach (Schema::getTableListing(null, false) as $table) {
            $columns = Schema::getColumnListing($table);
            $indexes = Schema::getIndexes($table);
            $foreignKeys = array_column(Schema::getForeignKeys($table), 'columns');

            // Cover conventional references that do not declare a constraint,
            // such as session and authentication audit references.
            foreach (['barangay_id', 'user_id', 'resident_id'] as $column) {
                if (in_array($column, $columns, true)) {
                    $foreignKeys[] = [$column];
                }
            }

            foreach ($foreignKeys as $foreignColumns) {
                if ($this->covered($indexes, $foreignColumns)) {
                    continue;
                }

                $indexColumns = array_values(array_unique([
                    ...$foreignColumns,
                    ...array_values(array_intersect(['status', 'created_at'], $columns)),
                ]));
                $name = self::PREFIX.substr(hash('sha256', $table.':'.implode(',', $foreignColumns)), 0, 24);
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($indexColumns, $name));
                $indexes[] = ['columns' => $indexColumns];
            }
        }
    }

    public function down(): void
    {
        foreach (Schema::getTableListing(null, false) as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (str_starts_with($index['name'], self::PREFIX)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index['name']));
                }
            }
        }
    }

    private function covered(array $indexes, array $columns): bool
    {
        foreach ($indexes as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }
};
