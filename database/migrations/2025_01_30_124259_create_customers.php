<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('custom_id');
            $table->string('name');
            $table->string('email');
            $table->string('company');
            $table->string('city');
            $table->string('country');
            $table->date('birthday');
            $table->timestamps();
        });
    }
};
