<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;

abstract class BaseRepository
{
    protected $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function all(): Collection
    {
        return $this->model->all();
    }

    public function findById(int $id): ?Model
    {
        return $this->model->find($id);
    }

    public function create(array $data): Model
    {
        return $this->model->create($data);

    }

    public function update(Model $model, array $data): bool
    {
        return $model->update($data);
    }

    public function delete(Model $model): bool
    {
        return $model->delete();
    }

    public function findByCondition(array $condition): Collection
    {
        return $this->model->where($condition)->get();
    }

    public function findOneByCondition(array $condition): ?Model
    {
        return $this->model->where($condition)->first();
    }

    public function paginate(int $perPage = 15, array $condition = [])
    {
        $query = $this->model->query();

        if (!empty($condition)) {
            $query->where($condition);
        }

        return $query->paginate($perPage);
    }
}
