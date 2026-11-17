<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->string('user_id', 64)->charset('ascii')->collation('ascii_bin')->primary();
            $table->bigInteger('balance')->default(0);
        });

        Schema::create('ledger', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 64)->charset('ascii')->collation('ascii_bin');
            $table->enum('type', ['credit', 'debit']);
            $table->unsignedInteger('amount');
            $table->timestamp('created_at', 6)->useCurrent();
            $table->foreign('user_id')->references('user_id')->on('wallets')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_nonnegative CHECK (balance >= 0)');
        DB::statement('ALTER TABLE ledger ADD CONSTRAINT ledger_positive_amount CHECK (amount BETWEEN 1 AND 1000000000)');
        DB::unprepared("CREATE TRIGGER ledger_no_update BEFORE UPDATE ON ledger FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger rows are immutable'");
        DB::unprepared("CREATE TRIGGER ledger_no_delete BEFORE DELETE ON ledger FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger rows are immutable'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger');
        Schema::dropIfExists('wallets');
    }
};
