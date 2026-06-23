<?php

namespace App\Traits;

trait WithPerPage
{
    public function mountWithPerPage(): void
    {
        $saved = (int) request()->cookie('crm_per_page', 25);
        if (in_array($saved, [25, 50, 100, 250, 500, 1000])) {
            $this->perPage = $saved;
        }
    }
}
