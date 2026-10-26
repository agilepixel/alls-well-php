<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAllsWellStateTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('alls_well_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('state');
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('alls_well_state');
    }
}
