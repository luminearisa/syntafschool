<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Event — tabel `events`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class Event extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'events';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'campus_id' => 'integer',
            'school_event_type_id' => 'integer',
            'event_category_id' => 'integer',
            'template_id' => 'integer',
            'recurrence_id' => 'integer',
            'parent_event_id' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'is_multi_day' => 'boolean',
            'is_recurring' => 'boolean',
            'is_public' => 'boolean',
            'requires_registration' => 'boolean',
            'requires_consent' => 'boolean',
            'requires_ticket' => 'boolean',
            'is_paid' => 'boolean',
            'ticket_price' => 'decimal:2',
            'min_participants' => 'integer',
            'max_participants' => 'integer',
            'expected_participants' => 'integer',
            'actual_participants' => 'integer',
            'waitlist_enabled' => 'boolean',
            'waitlist_capacity' => 'integer',
            'organizer_id' => 'integer',
            'event_manager_id' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'registration_open_at' => 'datetime',
            'registration_close_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'version_number' => 'integer',
            'current_venue_id' => 'integer',
            'public_page_id' => 'integer',
            'budget_total' => 'decimal:2',
            'budget_committed' => 'decimal:2',
            'budget_actual' => 'decimal:2',
            'weather_dependency' => 'boolean',
            'tags' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    public function eventAccommodations(): HasMany
    {
        return $this->hasMany(EventAccommodation::class, 'event_id');
    }

    public function eventApprovals(): HasMany
    {
        return $this->hasMany(EventApproval::class, 'event_id');
    }

    public function eventAssetRequests(): HasMany
    {
        return $this->hasMany(EventAssetRequest::class, 'event_id');
    }

    public function eventAttendanceAdjustments(): HasMany
    {
        return $this->hasMany(EventAttendanceAdjustment::class, 'event_id');
    }

    public function eventBadges(): HasMany
    {
        return $this->hasMany(EventBadge::class, 'event_id');
    }

    public function eventBudgets(): HasMany
    {
        return $this->hasMany(EventBudget::class, 'event_id');
    }

    public function eventCateringRequests(): HasMany
    {
        return $this->hasMany(EventCateringRequest::class, 'event_id');
    }

    public function eventCertificates(): HasMany
    {
        return $this->hasMany(EventCertificate::class, 'event_id');
    }

    public function eventChangeRequests(): HasMany
    {
        return $this->hasMany(EventChangeRequest::class, 'event_id');
    }

    public function eventCheckins(): HasMany
    {
        return $this->hasMany(EventCheckin::class, 'event_id');
    }

    public function eventChecklists(): HasMany
    {
        return $this->hasMany(EventChecklist::class, 'event_id');
    }

    public function eventClosings(): HasMany
    {
        return $this->hasMany(EventClosing::class, 'event_id');
    }

    public function eventCommittees(): HasMany
    {
        return $this->hasMany(EventCommittee::class, 'event_id');
    }

    public function eventConsents(): HasMany
    {
        return $this->hasMany(EventConsent::class, 'event_id');
    }

    public function eventContingencys(): HasMany
    {
        return $this->hasMany(EventContingency::class, 'event_id');
    }

    public function eventCosts(): HasMany
    {
        return $this->hasMany(EventCost::class, 'event_id');
    }

    public function eventDailyClosings(): HasMany
    {
        return $this->hasMany(EventDailyClosing::class, 'event_id');
    }

    public function eventDocuments(): HasMany
    {
        return $this->hasMany(EventDocument::class, 'event_id');
    }

    public function eventEvaluations(): HasMany
    {
        return $this->hasMany(EventEvaluation::class, 'event_id');
    }

    public function eventGuests(): HasMany
    {
        return $this->hasMany(EventGuest::class, 'event_id');
    }

    public function eventIncidents(): HasMany
    {
        return $this->hasMany(EventIncident::class, 'event_id');
    }

    public function eventInventoryRequests(): HasMany
    {
        return $this->hasMany(EventInventoryRequest::class, 'event_id');
    }

    public function eventInvitations(): HasMany
    {
        return $this->hasMany(EventInvitation::class, 'event_id');
    }

    public function eventKpis(): HasMany
    {
        return $this->hasMany(EventKpi::class, 'event_id');
    }

    public function eventMedias(): HasMany
    {
        return $this->hasMany(EventMedia::class, 'event_id');
    }

    public function eventMedicalPlans(): HasMany
    {
        return $this->hasMany(EventMedicalPlan::class, 'event_id');
    }

    public function eventMobileDevices(): HasMany
    {
        return $this->hasMany(EventMobileDevice::class, 'event_id');
    }

    public function eventNotifications(): HasMany
    {
        return $this->hasMany(EventNotification::class, 'event_id');
    }

    public function eventObjectives(): HasMany
    {
        return $this->hasMany(EventObjective::class, 'event_id');
    }

    public function eventParticipants(): HasMany
    {
        return $this->hasMany(EventParticipant::class, 'event_id');
    }

    public function eventPrograms(): HasMany
    {
        return $this->hasMany(EventProgram::class, 'event_id');
    }

    public function eventProposals(): HasMany
    {
        return $this->hasMany(EventProposal::class, 'event_id');
    }

    public function eventPublicPages(): HasMany
    {
        return $this->hasMany(EventPublicPage::class, 'event_id');
    }

    public function eventRecurrences(): HasMany
    {
        return $this->hasMany(EventRecurrence::class, 'event_id');
    }

    public function eventRegistrationFields(): HasMany
    {
        return $this->hasMany(EventRegistrationField::class, 'event_id');
    }

    public function eventRegistrationTokens(): HasMany
    {
        return $this->hasMany(EventRegistrationToken::class, 'event_id');
    }

    public function eventRegistrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class, 'event_id');
    }

    public function eventReports(): HasMany
    {
        return $this->hasMany(EventReport::class, 'event_id');
    }

    public function eventResourceRequests(): HasMany
    {
        return $this->hasMany(EventResourceRequest::class, 'event_id');
    }

    public function eventRisks(): HasMany
    {
        return $this->hasMany(EventRisk::class, 'event_id');
    }

    public function eventSchedules(): HasMany
    {
        return $this->hasMany(EventSchedule::class, 'event_id');
    }

    public function eventSecurityPlans(): HasMany
    {
        return $this->hasMany(EventSecurityPlan::class, 'event_id');
    }

    public function eventSessionAttendances(): HasMany
    {
        return $this->hasMany(EventSessionAttendance::class, 'event_id');
    }

    public function eventSessions(): HasMany
    {
        return $this->hasMany(EventSession::class, 'event_id');
    }

    public function eventSpeakers(): HasMany
    {
        return $this->hasMany(EventSpeaker::class, 'event_id');
    }

    public function eventSponsors(): HasMany
    {
        return $this->hasMany(EventSponsor::class, 'event_id');
    }

    public function eventSponsorshipPackages(): HasMany
    {
        return $this->hasMany(EventSponsorshipPackage::class, 'event_id');
    }

    public function eventSurveyResponses(): HasMany
    {
        return $this->hasMany(EventSurveyResponse::class, 'event_id');
    }

    public function eventSyncBatchs(): HasMany
    {
        return $this->hasMany(EventSyncBatch::class, 'event_id');
    }

    public function eventTargets(): HasMany
    {
        return $this->hasMany(EventTarget::class, 'event_id');
    }

    public function eventTasks(): HasMany
    {
        return $this->hasMany(EventTask::class, 'event_id');
    }

    public function eventTransportationRequests(): HasMany
    {
        return $this->hasMany(EventTransportationRequest::class, 'event_id');
    }

    public function eventVendors(): HasMany
    {
        return $this->hasMany(EventVendor::class, 'event_id');
    }

    public function eventVenueRequests(): HasMany
    {
        return $this->hasMany(EventVenueRequest::class, 'event_id');
    }

    public function eventVenues(): HasMany
    {
        return $this->hasMany(EventVenue::class, 'event_id');
    }

    public function eventVersions(): HasMany
    {
        return $this->hasMany(EventVersion::class, 'event_id');
    }

    public function eventVolunteers(): HasMany
    {
        return $this->hasMany(EventVolunteer::class, 'event_id');
    }

    public function eventWaitlists(): HasMany
    {
        return $this->hasMany(EventWaitlist::class, 'event_id');
    }
}
