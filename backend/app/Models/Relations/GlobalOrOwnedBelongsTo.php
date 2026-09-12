<?php

namespace App\Models\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catalog read boundary, including historical rows. Does not authorize writes or branch access. */
class GlobalOrOwnedBelongsTo extends BelongsTo
{
    public function __construct(Builder $query, Model $child, string $foreignKey, string $relationName, private ?string $scopeRelation = null)
    {
        parent::__construct($query, $child, $foreignKey, 'id', $relationName);
    }

    private function account(Model $model): ?string
    {
        return $this->scopeRelation ? $model->{$this->scopeRelation}?->account_id : $model->account_id;
    }

    public function addConstraints()
    {
        parent::addConstraints();
        if (static::$constraints) {
            $account = $this->account($this->child);
            $this->query->where(fn ($q) => $q->whereNull($this->related->qualifyColumn('account_id'))->orWhere($this->related->qualifyColumn('account_id'), $account));
        }
    }

    public function addEagerConstraints(array $models)
    {
        parent::addEagerConstraints($models);
        if ($this->scopeRelation) {
            (new Collection($models))->loadMissing($this->scopeRelation);
        }
        $accounts = array_values(array_filter(array_unique(array_map(fn ($m) => $this->account($m), $models))));
        $this->query->where(fn ($q) => $q->whereNull($this->related->qualifyColumn('account_id'))->orWhereIn($this->related->qualifyColumn('account_id'), $accounts));
    }

    public function match(array $models, Collection $results, $relation)
    {
        parent::match($models, $results, $relation);
        // A mixed-account eager batch must still match each child to its own tenant.
        foreach ($models as $model) {
            $related = $model->getRelation($relation);
            if ($related && $related->account_id !== null && $related->account_id !== $this->account($model)) {
                $model->setRelation($relation, null);
            }
        }

        return $models;
    }
}
