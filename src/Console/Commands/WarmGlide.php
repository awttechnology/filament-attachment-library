<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use AwtTechnology\FilamentAttachmentLibrary\Facades\Glide;
use AwtTechnology\FilamentAttachmentLibrary\Facades\Resizer;
use AwtTechnology\FilamentAttachmentLibrary\Models\Attachment;

class WarmGlide extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'glide:warm
                            {--skip-existing : Skip attachments already present in the thumbnail cache}
                            {--preset= : Also warm a named Glide preset for each attachment}
                            {--path= : Only warm attachments whose path starts with this prefix}
                            {--chunk=100 : Batch size passed to chunkById}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pre-warm the Glide image cache for all image attachments';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $preset = $this->option('preset');
        $chunk  = (int) $this->option('chunk');

        if ($preset !== null && !array_key_exists($preset, config('glide.presets', []))) {
            $this->error("Preset [{$preset}] does not exist in config('glide.presets').");
            return self::FAILURE;
        }

        $total = Attachment::where('mime_type', 'LIKE', 'image/%')
            ->when($this->option('path'), fn ($q) => $q->where('path', 'LIKE', $this->option('path') . '%'))
            ->count();
        $this->info("Warming Glide cache for {$total} image attachment(s)...");

        $bar     = $this->output->createProgressBar($total);
        $warmed  = 0;
        $skipped = 0;
        $failed  = 0;

        Attachment::where('mime_type', 'LIKE', 'image/%')
            ->when($this->option('path'), fn ($q) => $q->where('path', 'LIKE', $this->option('path') . '%'))
            ->orderBy('id')
            ->chunkById($chunk, function ($attachments) use ($preset, &$warmed, &$skipped, &$failed, $bar) {
                foreach ($attachments as $attachment) {
                    try {
                        if ($this->option('skip-existing') && Cache::has('attachment-thumbnail-url:' . $attachment->id . ':h320')) {
                            $skipped++;
                            continue;
                        }

                        if (!Glide::imageIsSupported($attachment->full_path)) {
                            $skipped++;
                            continue;
                        }

                        $result = Resizer::src($attachment)->height(320)->resize();
                        $url    = $result['url'] ?? $attachment->url;

                        Cache::put('attachment-thumbnail-url:' . $attachment->id . ':h320', $url, now()->addDay());

                        if ($preset !== null) {
                            Glide::server()->makeImage($attachment->full_path, config("glide.presets.{$preset}"));
                        }

                        $warmed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->newLine();
                        $this->warn("Failed [{$attachment->id}] {$attachment->full_path}: {$e->getMessage()}");
                    } finally {
                        $bar->advance();
                    }
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Status', 'Count'],
            [['Warmed', $warmed], ['Skipped', $skipped], ['Failed', $failed]]
        );

        return self::SUCCESS;
    }
}
