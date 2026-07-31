<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDocumentNumberSequencesTable extends Migration
{
    public function up()
    {
        Schema::create('document_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->string('period', 20);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'document_type', 'period']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('document_number_sequences');
    }
}
