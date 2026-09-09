<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\AiRateLimitRepository;
use App\Repository\DayEntryRepository;
use App\Repository\PhotoRepository;
use App\Repository\PoiMediaRepository;
use App\Repository\TripRepository;
use App\Service\AiProviderResolver;
use App\Service\AiVisionCaptionService;
use App\Service\PhotoStorage;

/**
 * Job type "photo.caption". Payload: {"photo_id": int, "batch_id": string}
 * - one job per photo, dispatched in bulk by
 * PhotoController::startCaptionBatch() ("KI generiere Fotobeschreibung"
 * for every photo in the trip missing one, or "overwrite all"). Unlike the
 * manual single-photo button (PhotoController::caption(),
 * AiVisionCaptionService::describe() trying the whole provider chain per
 * call), this calls exactly ONE provider at a time
 * (AiVisionCaptionService::describeWith()) and drives a persistent,
 * cross-job rate-limit state (AiRateLimitRepository, shared by every
 * trip/user - the provider's RPM limit is per API key, not per user).
 *
 * Never throws a plain error just because a call is rate-limited or
 * temporarily failing - JobPostponedException reschedules the SAME photo
 * for later without spending an attempt, so a transient provider hiccup
 * never permanently fails a photo. A genuine problem (photo file missing
 * on disk) is a real throw, handled by the normal Worker markFailed()
 * backoff.
 *
 * Algorithm (confirmed with Stefan): start unthrottled. On the first
 * failure, wait 60s then resume at a conservative 12s/call pace. Every 3
 * consecutive successes at a throttled pace, probe a little faster (-2s,
 * floor 0) - stops improving itself as soon as a failure reappears, which
 * then either means the current pace is still too fast for THIS limit
 * (rare - failures under an already-throttled pace instead trigger a
 * provider switch below) or genuinely settles as "the safe rate" for this
 * session. A failure while ALREADY throttled indicates a different/harder
 * limit or an outage: advance to the next configured provider in
 * AiProviderResolver::resolveChain('vision') and reset the rate state
 * (fresh probing for the new model). Once the whole chain has been tried
 * once, stop cycling and just back off further on whichever provider is
 * current.
 */
final class PhotoCaptionHandler implements JobHandlerInterface
{
    private const SLOT = 'vision';
    private const INITIAL_THROTTLE_SECONDS = 12;
    private const COOLDOWN_SECONDS = 60;
    private const PROBE_STEP_SECONDS = 2;
    private const PROBE_SUCCESS_STREAK = 3;
    private const MAX_INTERVAL_SECONDS = 120;
    private const BACKOFF_GROWTH = 1.5;

    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly TripRepository $trips,
        private readonly DayEntryRepository $entries,
        private readonly PhotoStorage $storage,
        private readonly AiVisionCaptionService $visionCaption,
        private readonly AiProviderResolver $resolver,
        private readonly AiRateLimitRepository $rateLimit,
        private readonly PoiMediaRepository $poiMedia,
    ) {
    }

    public function handle(array $payload): void
    {
        $photoId = (int) ($payload['photo_id'] ?? 0);
        if ($photoId <= 0) {
            return;
        }

        $photo = $this->photos->findById($photoId);
        if ($photo === null || $photo['status'] !== 'ready') {
            return; // Deleted or no longer ready since the batch was started - nothing to do.
        }

        $entry = $this->entries->findById((int) $photo['day_entry_id']);
        $trip = $entry !== null ? $this->trips->findById((int) $entry['trip_id']) : null;
        if ($trip === null) {
            return;
        }

        $chain = $this->resolver->resolveChain(self::SLOT);
        if ($chain === []) {
            return; // No vision provider configured at all - cosmetic gap, not a job failure.
        }

        $state = $this->rateLimit->getOrCreate(self::SLOT);

        $providerIndex = 0;
        if ($state['providerConfigId'] !== null) {
            foreach ($chain as $i => $candidate) {
                if ($candidate['id'] === $state['providerConfigId']) {
                    $providerIndex = $i;
                    break;
                }
            }
            // Not found (that config was deleted/lost its key meanwhile) -
            // providerIndex stays 0, a fresh start on the current primary.
        }
        $provider = $chain[$providerIndex];

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($state['nextAllowedAt'] !== null) {
            $nextAllowed = new \DateTimeImmutable($state['nextAllowedAt'], new \DateTimeZone('UTC'));
            if ($nextAllowed > $now) {
                throw new JobPostponedException($nextAllowed);
            }
        }

        $storageId = $photo['source_photo_id'] !== null ? (int) $photo['source_photo_id'] : (int) $photo['id'];
        $path = $this->storage->derivativePath($storageId, 'web');
        if (!is_file($path)) {
            return; // Genuinely nothing to caption - not a rate-limit matter.
        }

        $address = !empty($photo['ai_address']) ? (string) $photo['ai_address'] : null;
        $poiByPhoto = $this->poiMedia->findPoiByPhotoForTrip((int) $trip['id']);
        $nearbyPoiName = $poiByPhoto[$photoId]['name'] ?? null;

        $caption = $this->visionCaption->describeWith(
            $provider,
            (string) file_get_contents($path),
            'image/jpeg',
            $trip['people_notes'],
            $address,
            $nearbyPoiName,
        );

        if ($caption !== null) {
            $this->onSuccess($provider['id'], $state, $now);
            $this->photos->updateVisionCaption($photoId, $caption);
            return;
        }

        $this->onFailure($chain, $providerIndex, $state, $now);
    }

    /**
     * @param array{intervalSeconds: int, consecutiveOk: int} $state
     */
    private function onSuccess(int $providerId, array $state, \DateTimeImmutable $now): void
    {
        $intervalSeconds = $state['intervalSeconds'];
        $consecutiveOk = $state['consecutiveOk'] + 1;
        if ($intervalSeconds > 0 && $consecutiveOk >= self::PROBE_SUCCESS_STREAK) {
            $intervalSeconds = max(0, $intervalSeconds - self::PROBE_STEP_SECONDS);
            $consecutiveOk = 0;
        }
        $nextAllowedAt = $intervalSeconds > 0 ? $now->modify("+{$intervalSeconds} seconds") : null;
        $this->rateLimit->update(self::SLOT, $providerId, $intervalSeconds, $consecutiveOk, $nextAllowedAt);
    }

    /**
     * @param list<array{id: int, baseUrl: string, model: string, apiKey: string, provider: string}> $chain
     * @param array{intervalSeconds: int} $state
     */
    private function onFailure(array $chain, int $providerIndex, array $state, \DateTimeImmutable $now): void
    {
        $provider = $chain[$providerIndex];

        if ($state['intervalSeconds'] === 0) {
            // First-ever failure: nothing paced yet - the classic "429 or
            // error" case. Wait the full minute, then resume cautiously.
            $until = $now->modify('+' . self::COOLDOWN_SECONDS . ' seconds');
            $this->rateLimit->update(self::SLOT, $provider['id'], self::INITIAL_THROTTLE_SECONDS, 0, $until);
            throw new JobPostponedException($until);
        }

        $nextIndex = $providerIndex + 1;
        if (isset($chain[$nextIndex])) {
            // Already throttled and still failing - a harder/different
            // limit, or an outage. Switch model, start fresh.
            $next = $chain[$nextIndex];
            $this->rateLimit->update(self::SLOT, $next['id'], 0, 0, null);
            throw new JobPostponedException($now);
        }

        // Whole chain tried once already - stop cycling, just back off
        // further on the current provider instead of spinning forever.
        $grownInterval = min(self::MAX_INTERVAL_SECONDS, (int) round($state['intervalSeconds'] * self::BACKOFF_GROWTH));
        $until = $now->modify("+{$grownInterval} seconds");
        $this->rateLimit->update(self::SLOT, $provider['id'], $grownInterval, 0, $until);
        throw new JobPostponedException($until);
    }
}
