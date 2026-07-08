<?php

namespace Tests\Feature\Api\Meetings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wave-3 Task 3.1 — parametrized 401 sweep for the Meetings module.
 *
 * The Meetings module exposes several sub-domains: meetings, decisions,
 * recommendations, agenda-items, attendees, categories, settings, and
 * notifications. All of them are mounted under `auth:sanctum`.
 *
 * Mirrors the template T-B (unauthenticated 401) from the coverage plan.
 */
class MeetingsUnauthenticatedTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('unauthenticatedEndpointProvider')]
    public function test_endpoint_returns_401_without_acting_as(string $method, string $path): void
    {
        $response = $this->json($method, $path);
        $response->assertStatus(401);
    }

    public static function unauthenticatedEndpointProvider(): array
    {
        $endpoints = [
            // Notifications (can leak PII — read is dangerous)
            ['GET', '/api/notifications'],
            ['GET', '/api/notifications/unread-count'],
            ['POST', '/api/notifications/1/read'],
            ['POST', '/api/notifications/read-all'],

            // Recommendations (Direction B, 2026-07-06: legacy /api/decisions/*
            // routes were retired and folded into /api/recommendations/*; the
            // legacy surface is gone and these tests must enumerate the
            // canonical route family only — do not re-add /api/decisions/...).
            ['GET', '/api/recommendations'],
            ['GET', '/api/recommendations/list'],
            ['POST', '/api/recommendations'],
            ['GET', '/api/recommendations/1'],
            ['PUT', '/api/recommendations/1'],
            ['PATCH', '/api/recommendations/1'],
            ['DELETE', '/api/recommendations/1'],
            ['POST', '/api/recommendations/1/accept'],
            ['POST', '/api/recommendations/1/reject'],
            ['POST', '/api/recommendations/1/defer'],
            ['POST', '/api/recommendations/1/complete'],
            ['POST', '/api/recommendations/1/approve'],

            // Agenda items — P0 IDOR fix: the standalone /api/agenda-items/{id}
            // route family was retired and replaced with the nested
            // /api/meetings/{meeting}/agenda-items/{agendaItem}/* family. The
            // auth guard still fires before route model binding, so the
            // unauthenticated sweep must enumerate the nested paths.
            ['PUT', '/api/meetings/1/agenda-items/1'],
            ['DELETE', '/api/meetings/1/agenda-items/1'],
            ['POST', '/api/meetings/1/agenda-items/1/approve'],
            ['POST', '/api/meetings/1/agenda-items/1/reject'],

            // Meeting settings
            ['GET', '/api/meeting-settings'],
            ['PUT', '/api/meeting-settings'],

            // Meeting categories
            ['GET', '/api/meeting-categories'],
            ['POST', '/api/meeting-categories'],
            ['PUT', '/api/meeting-categories/1'],
            ['DELETE', '/api/meeting-categories/1'],

            // Meetings — full CRUD + lifecycle + attendees + agenda
            ['GET', '/api/meetings'],
            ['POST', '/api/meetings'],
            ['GET', '/api/meetings/list'],
            ['GET', '/api/meetings/1'],
            ['PUT', '/api/meetings/1'],
            ['PATCH', '/api/meetings/1'],
            ['DELETE', '/api/meetings/1'],
            ['GET', '/api/meetings/1/attendees'],
            ['POST', '/api/meetings/1/attendees'],
            ['PUT', '/api/meetings/1/attendees/1'],
            ['DELETE', '/api/meetings/1/attendees/1'],
            ['GET', '/api/meetings/1/agenda-items'],
            ['POST', '/api/meetings/1/agenda-items'],
            ['POST', '/api/meetings/1/agenda-items/reorder'],
            ['POST', '/api/meetings/1/request-agenda'],
            ['POST', '/api/meetings/1/start'],
            ['POST', '/api/meetings/1/complete'],
            ['POST', '/api/meetings/1/cancel'],
            ['POST', '/api/meetings/1/minutes'],
        ];

        $cases = [];
        foreach ($endpoints as [$method, $path]) {
            $cases["{$method} {$path}"] = [$method, $path];
        }

        return $cases;
    }
}
