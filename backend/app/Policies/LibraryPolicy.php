<?php

namespace App\Policies;

use App\Models\Library;
use App\Models\User;

/**
 * Otorisasi modul Library.
 *
 * Setiap kemampuan dipetakan ke permission spatie yang sama dengan yang
 * dipakai middleware rute, sehingga penegakan di controller (bertahan
 * terhadap rute yang lupa diberi middleware) konsisten dengan rute itu sendiri.
 */
class LibraryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('library.view');
    }

    public function view(User $user, Library $library): bool
    {
        return $user->can('library.view');
    }

    public function create(User $user): bool
    {
        return $user->can('library.create');
    }

    public function update(User $user, Library $library): bool
    {
        return $user->can('library.update');
    }

    public function delete(User $user, Library $library): bool
    {
        return $user->can('library.delete');
    }

    public function transition(User $user, Library $library): bool
    {
        return $user->can('library.update');
    }

    public function viewSettings(User $user, Library $library): bool
    {
        return $user->can('library.settings.view');
    }

    public function updateSettings(User $user, Library $library): bool
    {
        return $user->can('library.settings.update');
    }

    public function viewLocations(User $user, Library $library): bool
    {
        return $user->can('library.location.view');
    }

    public function createLocation(User $user, Library $library): bool
    {
        return $user->can('library.location.create');
    }

    public function updateLocation(User $user, Library $library): bool
    {
        return $user->can('library.location.update');
    }

    public function deleteLocation(User $user, Library $library): bool
    {
        return $user->can('library.location.delete');
    }

    public function viewOperatingHours(User $user, Library $library): bool
    {
        return $user->can('library.operating_hours.view');
    }

    public function updateOperatingHours(User $user, Library $library): bool
    {
        return $user->can('library.operating_hours.update');
    }

    public function viewClosures(User $user, Library $library): bool
    {
        return $user->can('library.closure.view');
    }

    public function createClosure(User $user, Library $library): bool
    {
        return $user->can('library.closure.create');
    }

    public function updateClosure(User $user, Library $library): bool
    {
        return $user->can('library.closure.update');
    }

    public function deleteClosure(User $user, Library $library): bool
    {
        return $user->can('library.closure.delete');
    }

    public function viewNumbering(User $user, Library $library): bool
    {
        return $user->can('library.numbering.view');
    }

    public function updateNumbering(User $user, Library $library): bool
    {
        return $user->can('library.numbering.update');
    }

    public function viewDashboard(User $user, Library $library): bool
    {
        return $user->can('library.dashboard.view');
    }

    public function viewAudit(User $user): bool
    {
        return $user->can('library.audit.view');
    }
}