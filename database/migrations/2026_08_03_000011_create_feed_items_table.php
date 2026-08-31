<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rows for the icon-list panels (activity, notifications, schedule). One
     * table because all four panels render through the same component; `scope`
     * decides which panel a row belongs to.
     */
    public function up(): void
    {
        Schema::create('feed_items', function (Blueprint $table) {
            $table->id();
            /** dashboard_activity | dashboard_notifications | dashboard_schedule | history_activity */
            $table->string('scope')->index();
            /** Rich text runs: [{ text, strong? }] — lets one word be emphasised. */
            $table->json('segments');
            $table->string('detail')->nullable();
            $table->string('meta')->nullable();
            /** Key resolved against the client icon registry, e.g. "file-text". */
            $table->string('icon');
            /** lilac | butter — the pale tile behind the icon. */
            $table->string('tile')->default('lilac');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_items');
    }
};
