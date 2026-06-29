<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait HandlesPagination
{
    protected function adjustPageAfterDelete(string $pageName = 'page'): void
    {
        $paginator = $this->getRowsQuery()
            ->paginate(
                perPage: $this->perPage,
                pageName: $pageName,
            );

        $lastPage = max(
            $paginator->lastPage(),
            1,
        );

        if ($this->getPage($pageName) > $lastPage) {
            $this->setPage(
                $lastPage,
                $pageName,
            );
        }
    }

    /**
     * @return Builder<Model>
     */
    abstract protected function getRowsQuery(): Builder;
}
