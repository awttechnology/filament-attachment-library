<?php

use AwtTechnology\FilamentAttachmentLibrary\AttachmentManager;
use Illuminate\Support\Facades\Cache;

class GateInspectingManager extends AttachmentManager
{
    public ?bool $gateHeldDuringSync = null;

    public function updateFiles(?string $directory): void
    {
        $this->gateHeldDuringSync = Cache::has('attachment-library-last-sync:attachments:');
        parent::updateFiles($directory);
    }
}

class FailingOnceManager extends AttachmentManager
{
    public int $calls = 0;

    public function updateFiles(?string $directory): void
    {
        $this->calls++;
        if ($this->calls === 1) {
            throw new RuntimeException('remote listing failed');
        }
        parent::updateFiles($directory);
    }
}

it('claims the sync gate before syncing so a concurrent request skips', function () {
    $manager = new GateInspectingManager();

    $manager->directories(null);

    expect($manager->gateHeldDuringSync)->toBeTrue();
});

it('releases the gate when the sync fails so the next request retries', function () {
    $manager = new FailingOnceManager();

    expect(fn () => $manager->directories(null))->toThrow(RuntimeException::class);
    $manager->directories(null);

    expect($manager->calls)->toBe(2)
        ->and(Cache::has('attachment-library-last-sync:attachments:'))->toBeTrue();
});
