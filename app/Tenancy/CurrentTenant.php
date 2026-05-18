<?php

namespace App\Tenancy;

use App\Models\Restaurant;

class CurrentTenant
{
    protected ?Restaurant $tenant = null;

    public function set(Restaurant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Restaurant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
