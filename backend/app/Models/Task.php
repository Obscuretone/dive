<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Task extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'title',
        'description',
        'estimated_time',
        'due_date',
        'completed',
        'status',
        'parent_id',
        'order',
    ];

    protected $casts = [
        'completed' => 'boolean',
        'due_date' => 'datetime',
        'estimated_time' => 'integer',
        'order' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($task) {
            if (!$task->{$task->getKeyName()}) {
                $task->{$task->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function parent()
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Task::class, 'parent_id')->orderBy('order')->orderBy('created_at');
    }

    public function relatedTasks()
    {
        return $this->belongsToMany(Task::class, 'related_tasks', 'task_id', 'related_task_id');
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }
}
