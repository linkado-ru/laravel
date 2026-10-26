<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Attribution;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\Laravel\Support\Tracking\TrackingGate;
use Linkado\PhpSdk\DataObjects\ResolveTrackingClickData;
use Linkado\PhpSdk\DataObjects\TrackingClickData;
use Linkado\PhpSdk\LinkadoConnector;
use Throwable;
use WeakMap;

/** @internal Request-local verification; cookies and browser sequences are never authority. */
final class PreparedAttribution
{
    /** @var WeakMap<Request, array{fingerprint: string, click: ?TrackingClickData}> */
    private WeakMap $prepared;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly LinkadoConfiguration $configuration,
        private readonly TrackingGate $tracking,
    ) {
        $this->prepared = new WeakMap;
    }

    public function prepare(Request $request): ?TrackingClickData
    {
        if (! $this->configuration->lastValidVisit() || ! $this->tracking->allows($request)) {
            return null;
        }
        $fingerprint = $this->fingerprint($request);

        if (isset($this->prepared[$request]) && $this->prepared[$request]['fingerprint'] === $fingerprint) {
            return $this->prepared[$request]['click'];
        }

        // Verification belongs before the host transaction; consume must never perform HTTP.
        if ($this->database->connection($this->configuration->connection())->transactionLevel() !== 0) {
            return null;
        }
        $this->prepared[$request] = ['fingerprint' => $fingerprint, 'click' => null];
        $candidate = $this->candidate($request);

        if ($candidate === null || $this->configuration->mode() !== DeliveryMode::Live) {
            return null;
        }

        try {
            $connector = new LinkadoConnector($this->configuration->requiredToken(), $this->configuration->requiredBaseUrl());
            $click = $connector->trackingClicks()->resolve(new ResolveTrackingClickData(
                $this->configuration->requiredProgramKey(), $candidate['click_id'],
            ));
            $now = CarbonImmutable::now();

            if ($click === null || $click->program_key !== $this->configuration->requiredProgramKey()
                || $click->click_id !== $candidate['click_id'] || $click->visit_order < 1
                || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $click->referral_slug) !== 1
                || $click->created_at > $now || $click->expires_at <= $now || $click->expires_at <= $click->created_at) {
                return null;
            }

            $this->prepared[$request] = ['fingerprint' => $fingerprint, 'click' => $click];

            return $click;
        } catch (Throwable) {
            // An unavailable verifier must not interrupt signup or overwrite a trusted candidate.
            return null;
        }
    }

    public function cached(Request $request): ?TrackingClickData
    {
        return isset($this->prepared[$request]) && $this->prepared[$request]['fingerprint'] === $this->fingerprint($request)
            ? $this->prepared[$request]['click'] : null;
    }

    /** @return array{click_id: string, referral_slug: ?string}|null */
    public function candidate(Request $request): ?array
    {
        $snapshot = $request->cookie('lk_attribution');

        if ($snapshot !== null) {
            if (! is_string($snapshot) || strlen($snapshot) > 2048) {
                return null;
            }

            try {
                $data = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return null;
            }

            if (! is_array($data) || ($data['version'] ?? null) !== 1
                || ($data['program_key'] ?? null) !== $this->configuration->programKey()
                || ! AttributionIdentifiers::click($data['click_id'] ?? null)
                || ! AttributionIdentifiers::referral($data['referral_slug'] ?? null)) {
                return null;
            }

            return ['click_id' => $data['click_id'], 'referral_slug' => $data['referral_slug']];
        }

        $click = $request->cookie($this->configuration->trackingClickCookie());
        $slug = $request->cookie($this->configuration->trackingReferralCookie());

        return AttributionIdentifiers::validCandidates($click, $slug) && AttributionIdentifiers::click($click)
            ? ['click_id' => $click, 'referral_slug' => AttributionIdentifiers::referral($slug) ? $slug : null]
            : null;
    }

    private function fingerprint(Request $request): string
    {
        return hash('sha256', serialize([
            $request->cookie('lk_attribution'),
            $request->cookie($this->configuration->trackingVisitorCookie()),
            $request->cookie($this->configuration->trackingClickCookie()),
            $request->cookie($this->configuration->trackingReferralCookie()),
            $this->configuration->programKey(),
        ]));
    }
}
