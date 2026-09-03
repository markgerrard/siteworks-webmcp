<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive companion to the squashed demo schema: import warnings that a human
// should look at before publishing live on the product itself, not only in the
// tool receipt that produced it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->json('review_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropColumn('review_notes');
        });
    }
};
