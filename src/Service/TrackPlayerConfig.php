<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Track-Player speed/color settings (PLAN.md), read fresh per request
 * (Settings.php's own rule: never resolve in a container factory, always
 * at call time) and handed to templates as plain data attributes - the
 * player itself is pure client-side JS (track-player.js), the server
 * never animates anything. Shared by both places the big interactive map
 * renders (TripMapController's standalone /map page and
 * TripManageController's unified edit page) so the two don't drift apart.
 */
final class TrackPlayerConfig
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @return array<string, string>
     */
    public function forTemplate(): array
    {
        return [
            'secondsPerRealMinute' => $this->settings->get('trackplayer.seconds_per_real_minute'),
            'holdSecondsPerPoint' => $this->settings->get('trackplayer.hold_seconds_per_point'),
            'longGapSeconds' => $this->settings->get('trackplayer.long_gap_seconds'),
            'colorPlayed' => $this->settings->get('trackplayer.color_played'),
            'colorUpcoming' => $this->settings->get('trackplayer.color_upcoming'),
            'poiMatchMeters' => $this->settings->get('poi.photo_match_meters'),
        ];
    }
}
