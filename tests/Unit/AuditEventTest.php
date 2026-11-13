<?php

namespace Tests\Unit;

use App\Support\AuditEvent;
use Tests\TestCase;

class AuditEventTest extends TestCase
{
    public function test_every_known_event_has_a_severity_mapping(): void
    {
        foreach (array_keys(AuditEvent::LABELS) as $event) {
            $this->assertArrayHasKey($event, AuditEvent::SEVERITIES, "Event {$event} belum punya mapping severity.");
        }
    }

    public function test_severity_values_are_valid(): void
    {
        foreach (AuditEvent::SEVERITIES as $event => $severity) {
            $this->assertContains($severity, ['LOW', 'MEDIUM', 'HIGH'], "Event {$event} punya severity tidak valid: {$severity}.");
        }
    }

    public function test_procurement_events_have_labels_and_expected_severity(): void
    {
        $events = [
            AuditEvent::PURCHASE_REQUEST_SUBMITTED => 'MEDIUM',
            AuditEvent::PURCHASE_REQUEST_APPROVED => 'MEDIUM',
            AuditEvent::PURCHASE_REQUEST_REJECTED => 'MEDIUM',
            AuditEvent::PURCHASE_REQUEST_CANCELLED => 'MEDIUM',
            AuditEvent::PURCHASE_ORDER_ISSUED => 'MEDIUM',
            AuditEvent::PURCHASE_ORDER_CANCELLED => 'MEDIUM',
            AuditEvent::APPROVAL_SETTING_CHANGED => 'HIGH',
        ];

        foreach ($events as $event => $severity) {
            $this->assertArrayHasKey($event, AuditEvent::LABELS);
            $this->assertSame($severity, AuditEvent::SEVERITIES[$event]);
        }
    }

    public function test_permission_grant_and_revoke_are_high_severity(): void
    {
        $this->assertSame('HIGH', AuditEvent::SEVERITIES[AuditEvent::USER_BRANCH_PERMISSION_GRANTED]);
        $this->assertSame('HIGH', AuditEvent::SEVERITIES[AuditEvent::USER_BRANCH_PERMISSION_REVOKED]);
    }
}
