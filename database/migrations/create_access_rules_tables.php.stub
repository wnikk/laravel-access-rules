<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Return name of tables
     *
     * @return stdClass{role:string, Permission:string, owners:string, inheritance:string}
     */
    protected function getTableNames(): stdClass
    {

        $tables = config('access.table_names');

        if (empty($tables)) {
            throw new \Exception('Error: config/access.php not loaded. Run [php artisan config:clear] and try again.');
        }

        return (object)($tables);
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $tableNames = $this->getTableNames();

        Schema::create($tableNames->rule, function (Blueprint $table) {
            $table->increments('id');
            $table->integer('parent_id')->default(0);
            $table->string('guard_name', 128)->unique();
            $table->string('options', 255)->nullable();
            $table->string('title', 128)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamp('created_at');
            $table->softDeletes();
        });
        Schema::create($tableNames->permission, function (Blueprint $table) use ($tableNames) {
            $table->increments('id');
            $table->integer('owner_id');
            $table->integer('rule_id');
            $table->boolean('permission')->default(false);
            $table->string('option')->nullable();
            $table->timestamp('created_at');

            //$table->foreign('owner_id')
            //    ->references('id') // owners id
            //    ->on($tableNames->owners)
            //    ->onDelete('cascade');

            //$table->foreign('role_id')
            //    ->references('id') // rule id
            //    ->on($tableNames->rule)
            //    ->onDelete('cascade');
        });

        Schema::create($tableNames->owner, function (Blueprint $table) {
            $table->increments('id');
            $table->integer('type')->default(0);
            $table->string('original_id', 64)->nullable();
            $table->string('name',128)->nullable();
            $table->timestamp('created_at');

            //$table->primary(['type', 'original_id']);
        });

        Schema::create($tableNames->inheritance, function (Blueprint $table) use ($tableNames) {
            $table->increments('id');
            $table->integer('owner_id');
            $table->integer('owner_parent_id');
            $table->timestamp('created_at');

            //$table->foreign('owner_id')
            //    ->references('id') // owners id
            //    ->on($tableNames->owners)
            //    ->onDelete('cascade');
        });

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $tableNames = $this->getTableNames();

        Schema::drop($tableNames->rule);
        Schema::drop($tableNames->permission);
        Schema::drop($tableNames->owner);
        Schema::drop($tableNames->inheritance);
    }
};
