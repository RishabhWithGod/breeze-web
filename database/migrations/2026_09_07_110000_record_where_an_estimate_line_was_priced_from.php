<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Says, on the line itself, where its rate came from.
 *
 * An estimate now mixes two kinds of number: rates the company has actually
 * charged, read from its own estimating workbooks, and the plausible constants
 * in `config/estimating.php` that stand in for anything the price book has
 * never seen. On the page they look identical — a description, a quantity and a
 * figure — and that is the dangerous part: a guess that reads like a quote gets
 * sent to a client as one.
 *
 * Nullable, and nothing is backfilled. Lines written before this existed came
 * from the engine or the old catalog and there is no way to tell which now;
 * claiming otherwise would be inventing provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            if (! Schema::hasColumn('estimate_items', 'pricing_source')) {
                // 'price-book', 'catalog' or 'engine'. A string rather than an
                // enum, so a fourth source later is a code change and not a
                // table rebuild.
                $table->string('pricing_source', 24)->nullable()->after('source');
            }

            if (! Schema::hasColumn('estimate_items', 'price_book_item_id')) {
                /*
                 * Which rate was quoted. Nulled rather than cascaded if that
                 * rate is later deleted: the estimate keeps the figure it was
                 * sent out at, because a client was quoted it.
                 */
                $table->foreignId('price_book_item_id')->nullable()->after('pricing_source')
                    ->constrained('price_book_items')->nullOnDelete();
            }

            if (! Schema::hasColumn('estimate_items', 'pricing_confidence')) {
                // How the match was made: 'exact', 'tag' or 'words'. A word
                // match is a suggestion — "Panel" finding one specific
                // panelboard — and the screen marks it for a second look.
                $table->string('pricing_confidence', 16)->nullable()->after('price_book_item_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            if (Schema::hasColumn('estimate_items', 'price_book_item_id')) {
                $table->dropForeign(['price_book_item_id']);
                $table->dropColumn('price_book_item_id');
            }

            foreach (['pricing_source', 'pricing_confidence'] as $column) {
                if (Schema::hasColumn('estimate_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
