<?php

namespace App\Livewire\Settings;

use Flux\Flux;
use Livewire\Component;

class DeleteUserForm extends Component
{
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(): void
    {
        Flux::toast('You cannot delete your own account.', variant: 'warning');
    }
}
