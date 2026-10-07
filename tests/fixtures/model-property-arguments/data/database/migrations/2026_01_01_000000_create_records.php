<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up():void {
        Schema::create('records',function(Blueprint $table):void {
            $table->integer('context_id')->nullable();
            $table->string('context_type')->nullable();
        });
    }
};
