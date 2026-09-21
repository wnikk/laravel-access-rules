<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Return name of tables
     *
     * @return object{owner:string, inheritance:string, rule:string, permission:string}
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

        Schema::create($tableNames->owner, function (Blueprint $table) {
            $table->increments('id');
            $table->integer('type')->default(0);
            $table->string('original_id', 64)->nullable();
            $table->string('name',128)->nullable();
            $table->timestamp('created_at');

            $table->unique(['type', 'original_id'], 'acr_owner_unique');
        });

        Schema::create($tableNames->inheritance, function (Blueprint $table) use ($tableNames) {
            $table->increments('id');
            $table->unsignedInteger('owner_id');
            $table->unsignedInteger('owner_parent_id');
            $table->timestamp('created_at');

            $table->unique(['owner_id', 'owner_parent_id'], 'acr_inheritance_unique');
            $table->index('owner_parent_id', 'acr_inheritance_parent');

            $table->foreign('owner_id')
                ->references('id') // owners id
                ->on($tableNames->owner)
                ->onDelete('cascade');

            $table->foreign('owner_parent_id', 'acr_inheritance_parent_fk')
                ->references('id') // owners id
                ->on($tableNames->owner)
                ->onDelete('cascade');
        });

        Schema::create($tableNames->rule, function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->default(0);
            $table->string('guard_name', 128)->unique();
            $table->string('options', 255)->nullable();
            $table->string('resource', 64)->nullable();
            $table->jsonb('condition')->nullable();
            $table->string('title', 128)->nullable();
            $table->string('description', 255)->nullable();
            // Where the rule comes from: code, custom, import. See Wnikk\LaravelAccessRules\Models\RuleOrigin.
            $table->string('origin', 16)->default('code');
            $table->timestamp('created_at');
        });
        Schema::create($tableNames->permission, function (Blueprint $table) use ($tableNames) {
            $table->increments('id');
            $table->unsignedInteger('owner_id');
            $table->unsignedInteger('rule_id');
            $table->boolean('permission')->default(false);
            $table->string('option')->nullable();
            $table->jsonb('condition')->nullable();
            $table->timestamp('created_at');

            $table->unique(['owner_id', 'rule_id', 'option', 'permission'], 'acr_permission_unique');

            $table->foreign('owner_id')
                ->references('id') // owners id
                ->on($tableNames->owner)
                ->onDelete('cascade');

            $table->foreign('rule_id')
                ->references('id') // rule id
                ->on($tableNames->rule)
                ->onDelete('cascade');
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

        // Tables that hold foreign keys go first: PostgreSQL and MySQL refuse to drop a table others point at.
        Schema::drop($tableNames->permission);
        Schema::drop($tableNames->inheritance);
        Schema::drop($tableNames->rule);
        Schema::drop($tableNames->owner);
    }
};
