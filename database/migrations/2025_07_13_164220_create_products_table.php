<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->text('description');
        $table->string('short_description', 150)->nullable();
        $table->decimal('price', 10, 2);
        $table->integer('stock')->default(1);

        //LLAVES FOREANEAS
        $table->unsignedBigInteger('provider_id');
            $table->foreign('provider_id')->references('id')->on('providers');
        
        $table->unsignedBigInteger('brand_id');
            $table->foreign('brand_id')->references('id')->on('brands');

        $table->unsignedBigInteger('category_id');
            $table->foreign('category_id')->references('id')->on('categories');

        $table->enum('status', ['available', 'offstock', 'not_available'])->default('available');
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
