<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(CurrentTenant::class);

        if (! $tenant->check()) {
            return;
        }

        $builder->where($model->qualifyColumn('restaurant_id'), $tenant->id());
    }
}
