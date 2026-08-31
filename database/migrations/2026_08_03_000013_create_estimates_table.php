<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Client-facing estimates produced from takeoffs. */
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            /** Human reference, e.g. EST-1082. Generated server-side. */
            $table->string('number')->unique();
            $table->string('client')->index();
            /** Free-text project name — estimates outlive the takeoff they came from. */
            $table->string('project');
            $table->date('issued_on')->index();
            $table->decimal('amount', 12, 2);
            /** draft | sent | approved | rejected */
            $table->string('status')->index();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimates');
    }
};
