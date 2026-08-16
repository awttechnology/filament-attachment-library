<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'filament-attachment-library:install
                            {--force : Replace any migrations that were already published}
                            {--no-migrate : Publish the migrations without running them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish the attachment library migrations in dependency order and run them';

    /**
     * The migration stubs in the order they must run. Later migrations depend on
     * tables created by earlier ones, so this order is significant and must not
     * be left to the alphabetical sort a plain vendor:publish would apply.
     *
     * @var list<string>
     */
    protected array $migrations = [
        'create_attachments_table',
        'create_attachables_table',
        'add_focal_point_to_attachments_table',
        'add_indexes_to_attachments_table',
        'add_collection_to_attachables_table',
        'add_order_to_attachables_table',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $stubPath = realpath(__DIR__ . '/../../../database/migrations');
        $target   = database_path('migrations');

        File::ensureDirectoryExists($target);

        // Sequential timestamps so the published filenames sort into the same
        // order as $this->migrations regardless of when install is run.
        $timestamp = Carbon::now();
        $published = 0;
        $skipped   = 0;

        foreach ($this->migrations as $name) {
            $stub = "{$stubPath}/{$name}.php.stub";

            if (! File::exists($stub)) {
                $this->error("Missing migration stub: {$name}.php.stub");

                return self::FAILURE;
            }

            $existing = File::glob("{$target}/*_{$name}.php");

            if ($existing !== []) {
                if (! $this->option('force')) {
                    $this->components->twoColumnDetail($name, '<fg=yellow>SKIPPED (already published)</>');
                    $skipped++;

                    continue;
                }

                // --force: drop the old copies so we don't leave duplicates behind.
                File::delete($existing);
            }

            $timestamp = $timestamp->addSecond();
            $filename  = $timestamp->format('Y_m_d_His') . "_{$name}.php";

            File::copy($stub, "{$target}/{$filename}");
            $this->components->twoColumnDetail($filename, '<fg=green>PUBLISHED</>');
            $published++;
        }

        $this->newLine();
        $this->info("Published {$published} migration(s), skipped {$skipped}.");

        if ($this->option('no-migrate')) {
            $this->comment('Skipped running migrations (--no-migrate). Run "php artisan migrate" when ready.');

            return self::SUCCESS;
        }

        if ($published > 0) {
            $this->newLine();
            $this->call('migrate');
        }

        return self::SUCCESS;
    }
}
