<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\HandlesPagination;
use Livewire\Component;
use Livewire\WithPagination;

abstract class BaseIndexComponent extends Component
{
    use HandlesPagination;
    use WithPagination;

    public int $perPage = 10;
}
