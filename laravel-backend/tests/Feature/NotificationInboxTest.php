<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_only_see_and_mark_their_own_clinic_notifications_as_read(): void
    {
        $clinic = Clinic::create([
            'name' => 'Clinique Notifications',
            'city' => 'Dakar',
            'country' => 'Sénégal',
            'code' => 'NOTIFICATIONS',
            'subscription_plan' => 'BASIC',
            'admin_email' => 'notifications@example.test',
            'status' => 'ACTIVE',
        ]);
        $otherClinic = Clinic::create([
            'name' => 'Autre Clinique',
            'city' => 'Thiès',
            'country' => 'Sénégal',
            'code' => 'OTHER_NOTIFICATIONS',
            'subscription_plan' => 'BASIC',
            'admin_email' => 'other-notifications@example.test',
            'status' => 'ACTIVE',
        ]);
        $user = User::factory()->create(['clinic_id' => $clinic->id]);
        $otherUser = User::factory()->create(['clinic_id' => $clinic->id]);

        $notification = UserNotification::create([
            'clinic_id' => $clinic->id,
            'user_id' => $user->id,
            'type' => 'INFO',
            'title' => 'Rendez-vous',
            'message' => 'Un rendez-vous a été attribué.',
        ]);
        UserNotification::create([
            'clinic_id' => $clinic->id,
            'user_id' => $otherUser->id,
            'type' => 'INFO',
            'title' => 'Privé',
            'message' => 'Notification personnelle.',
        ]);
        $foreignNotification = UserNotification::create([
            'clinic_id' => $otherClinic->id,
            'user_id' => $user->id,
            'type' => 'INFO',
            'title' => 'Autre clinique',
            'message' => 'Ne doit pas être accessible.',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $notification->id)
            ->assertJsonPath('meta.unread_count', 1);

        $this->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.read', true);

        $this->patchJson("/api/v1/notifications/{$foreignNotification->id}/read")
            ->assertNotFound();

        $this->getJson('/api/v1/notifications')
            ->assertJsonPath('meta.unread_count', 0);
    }
}
