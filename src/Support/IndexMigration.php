<?php

namespace Overthink\DbSnapshot\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;

/**
 * A migration adding indexes to the date columns that "recent" rules filter
 * on, so mysqldump --where doesn't have to scan the whole table on the server.
 */
final class IndexMigration
{
    /**
     * Non-indexed date columns that $profile filters on.
     *
     * @return array<string, list<string>> table => columns
     */
    public static function missingIndexes(Profile $profile, Analysis $analysis): array
    {
        $missing = [];

        foreach ($profile->tables as $table => $rule) {
            $column = $analysis->table($table)?->dateColumn((string) $rule->column);

            if ($rule->mode === TableMode::Recent && $column !== null && ! $column->indexed) {
                $missing[$table][] = $column->name;
            }
        }

        ksort($missing);

        return $missing;
    }

    /**
     * @param  array<string, list<string>>  $indexes  table => columns
     */
    public static function render(array $indexes): string
    {
        $up = [];
        $down = [];

        foreach ($indexes as $table => $columns) {
            $add = implode("\n", array_map(fn (string $column): string => "            \$table->index('{$column}');", $columns));
            $drop = implode("\n", array_map(fn (string $column): string => "            \$table->dropIndex(['{$column}']);", $columns));

            $up[] = "        Schema::table('{$table}', function (Blueprint \$table) {\n{$add}\n        });";
            $down[] = "        Schema::table('{$table}', function (Blueprint \$table) {\n{$drop}\n        });";
        }

        $up = implode("\n\n", $up);
        $down = implode("\n\n", $down);

        return <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        /**
         * Indexes the date columns that db-snapshot profiles filter on, so
         * snapshot:pull can read recent rows without scanning whole tables.
         *
         * On large tables building an index takes a while; InnoDB builds it
         * online, so reads and writes keep working in the meantime.
         */
        return new class extends Migration
        {
            public function up(): void
            {
        {$up}
            }

            public function down(): void
            {
        {$down}
            }
        };

        PHP;
    }

    /**
     * @param  array<string, list<string>>  $indexes
     */
    public static function write(string $directory, array $indexes, ?CarbonImmutable $now = null): string
    {
        $path = rtrim($directory, '/').'/'.($now ?? CarbonImmutable::now())->format('Y_m_d_His').'_add_snapshot_date_indexes.php';

        File::ensureDirectoryExists($directory);
        File::put($path, self::render($indexes));

        return $path;
    }
}
