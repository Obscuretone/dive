<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTasksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary(); // UUID as primary key
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('order')->unsigned()->nullable();
            $table->integer('estimated_time')->unsigned()->nullable();
            $table->dateTime('due_date')->nullable();
            $table->boolean('completed')->default(false); // Default to not completed
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->uuid('parent_id')->nullable(); // Self-referencing foreign key
            $table->timestamps();

            // Define foreign key constraint
            $table->foreign('parent_id')->references('id')->on('tasks')->onDelete('set null');
        });

        // Pivot table for related tasks
        Schema::create('related_tasks', function (Blueprint $table) {
            $table->uuid('task_id');
            $table->uuid('related_task_id');
            $table->primary(['task_id', 'related_task_id']);

            // Define foreign key constraints
            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
            $table->foreign('related_task_id')->references('id')->on('tasks')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('related_tasks');
        Schema::dropIfExists('tasks');
    }
}
