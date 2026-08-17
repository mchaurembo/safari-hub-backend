<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safari Hub Payment Engine — domain tables + evolve legacy `payments`.
 * Amounts use amount_minor (integer). See PaymentMoney (major × 100).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('type', 32); // card | mobile_money | bank
            $table->string('provider', 64)->nullable();
            $table->string('status', 32)->default('active'); // active | inactive
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('configuration')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('driver', 64); // stub | selcom | flutterwave | etc.
            $table->string('status', 32)->default('active');
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->json('supported_methods')->nullable();
            $table->json('configuration')->nullable(); // non-secret flags only
            $table->timestamps();
        });

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('module', 64); // transport | garage | marketplace | *
            $table->string('status', 32)->default('active');
            $table->string('calculation_type', 32)->default('percent'); // percent | fixed
            $table->decimal('platform_rate', 8, 4)->default(0); // e.g. 10.0000 = 10%
            $table->unsignedBigInteger('platform_fixed_minor')->default(0);
            $table->json('recipient_rules')->nullable();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_reference', 64)->nullable()->unique()->after('id');
            $table->foreignId('payer_id')->nullable()->after('payment_reference')->constrained('users')->nullOnDelete();
            $table->nullableMorphs('payable');
            $table->string('transaction_type', 32)->default('charge')->after('payable_id');
            $table->string('currency', 3)->default('TZS')->after('amount');
            $table->unsignedBigInteger('amount_minor')->default(0)->after('currency');
            $table->foreignId('payment_method_id')->nullable()->after('payment_method')->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('gateway_id')->nullable()->after('payment_method_id')->constrained('payment_gateways')->nullOnDelete();
            $table->string('gateway_reference')->nullable()->after('transaction_reference');
            $table->string('idempotency_key', 128)->nullable()->unique()->after('gateway_reference');
            $table->string('payment_url', 1024)->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('successful_attempt_id')->nullable();
        });

        $this->relaxPaymentsLegacyColumns();

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('gateway_id')->nullable()->constrained('payment_gateways')->nullOnDelete();
            $table->string('status', 32)->default('INITIATED');
            $table->string('gateway_reference')->nullable();
            $table->string('payment_url', 1024)->nullable();
            $table->string('phone')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'attempt_number']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('successful_attempt_id')
                ->references('id')
                ->on('payment_attempts')
                ->nullOnDelete();
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->string('transaction_type', 32); // INITIATED, SUBMITTED, PROCESSING, SUCCESS, FAILED, REFUNDED, WEBHOOK, VERIFY
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('currency', 3)->default('TZS');
            $table->string('gateway_reference')->nullable();
            $table->string('status', 32)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->timestamps();

            $table->index(['payment_id', 'created_at']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->nullableMorphs('recipient'); // recipient_type, recipient_id
            $table->string('allocation_type', 64); // PLATFORM_COMMISSION | PROVIDER | DRIVER | GARAGE | TECHNICIAN | GATEWAY_FEE | TAX
            $table->unsignedBigInteger('gross_amount_minor')->default(0);
            $table->unsignedBigInteger('commission_amount_minor')->default(0);
            $table->unsignedBigInteger('net_amount_minor')->default(0);
            $table->string('currency', 3)->default('TZS');
            $table->string('status', 32)->default('PENDING'); // PENDING | AVAILABLE | PAID_OUT | REVERSED
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'allocation_type']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_reference', 64)->unique();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('TZS');
            $table->string('reason')->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->string('gateway_reference')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('currency', 3)->default('TZS');
            $table->bigInteger('available_balance_minor')->default(0);
            $table->bigInteger('pending_balance_minor')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallet_accounts')->cascadeOnDelete();
            $table->string('type', 64); // SERVICE_EARNING | PLATFORM_COMMISSION | PAYOUT | REFUND | ADJUSTMENT | REVERSAL
            $table->string('reference', 64)->nullable();
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->bigInteger('balance_after_minor');
            $table->nullableMorphs('source'); // source_type, source_id
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->string('payout_reference', 64)->unique();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('wallet_accounts')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('TZS');
            $table->string('payout_method', 64)->nullable();
            $table->foreignId('gateway_id')->nullable()->constrained('payment_gateways')->nullOnDelete();
            $table->string('gateway_reference')->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('event_id', 191)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('status', 32)->default('received'); // received | processed | ignored | failed
            $table->json('payload')->nullable();
            $table->text('processing_notes')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallet_accounts');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payment_transactions');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['successful_attempt_id']);
        });
        Schema::dropIfExists('payment_attempts');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['payer_id']);
            $table->dropForeign(['payment_method_id']);
            $table->dropForeign(['gateway_id']);
            $table->dropForeign(['booking_id']);
            $table->dropMorphs('payable');
            $table->dropColumn([
                'payment_reference', 'payer_id', 'transaction_type', 'currency', 'amount_minor',
                'payment_method_id', 'gateway_id', 'gateway_reference', 'idempotency_key',
                'payment_url', 'initiated_at', 'processing_at', 'paid_at', 'failed_at',
                'expired_at', 'failure_reason', 'metadata', 'successful_attempt_id',
            ]);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
        });

        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('payment_gateways');
        Schema::dropIfExists('payment_methods');
    }

    /**
     * Make legacy columns nullable for polymorphic payables without doctrine/dbal.
     */
    private function relaxPaymentsLegacyColumns(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        try {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['booking_id']);
            });
        } catch (Throwable) {
            // SQLite / already dropped
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE payments MODIFY booking_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE payments MODIFY payment_method VARCHAR(255) NULL');
            DB::statement("ALTER TABLE payments MODIFY status VARCHAR(255) NOT NULL DEFAULT 'INITIATED'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE payments ALTER COLUMN booking_id DROP NOT NULL');
            DB::statement('ALTER TABLE payments ALTER COLUMN payment_method DROP NOT NULL');
            DB::statement("ALTER TABLE payments ALTER COLUMN status SET DEFAULT 'INITIATED'");
        } elseif ($driver === 'sqlite') {
            // SQLite cannot easily MODIFY; rebuild payments with relaxed constraints.
            $this->rebuildSqlitePaymentsNullable();
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
        });
    }

    private function rebuildSqlitePaymentsNullable(): void
    {
        Schema::disableForeignKeyConstraints();

        DB::statement('
            CREATE TABLE payments_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                payment_reference VARCHAR(64) NULL,
                payer_id INTEGER NULL,
                booking_id INTEGER NULL,
                payable_type VARCHAR(255) NULL,
                payable_id INTEGER NULL,
                transaction_type VARCHAR(32) NOT NULL DEFAULT \'charge\',
                amount NUMERIC(10, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT \'TZS\',
                amount_minor INTEGER NOT NULL DEFAULT 0,
                payment_method VARCHAR(255) NULL,
                payment_method_id INTEGER NULL,
                gateway_id INTEGER NULL,
                transaction_reference VARCHAR(255) NULL,
                gateway_reference VARCHAR(255) NULL,
                idempotency_key VARCHAR(128) NULL,
                status VARCHAR(255) NOT NULL DEFAULT \'INITIATED\',
                payment_url VARCHAR(1024) NULL,
                initiated_at DATETIME NULL,
                processing_at DATETIME NULL,
                paid_at DATETIME NULL,
                failed_at DATETIME NULL,
                expired_at DATETIME NULL,
                failure_reason TEXT NULL,
                metadata TEXT NULL,
                successful_attempt_id INTEGER NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        $cols = 'id, payment_reference, payer_id, booking_id, payable_type, payable_id, transaction_type, amount, currency, amount_minor, payment_method, payment_method_id, gateway_id, transaction_reference, gateway_reference, idempotency_key, status, payment_url, initiated_at, processing_at, paid_at, failed_at, expired_at, failure_reason, metadata, successful_attempt_id, created_at, updated_at';
        DB::statement("INSERT INTO payments_new ({$cols}) SELECT {$cols} FROM payments");
        Schema::drop('payments');
        DB::statement('ALTER TABLE payments_new RENAME TO payments');
        DB::statement('CREATE UNIQUE INDEX payments_payment_reference_unique ON payments (payment_reference)');
        DB::statement('CREATE UNIQUE INDEX payments_idempotency_key_unique ON payments (idempotency_key)');
        DB::statement('CREATE INDEX payments_payable_type_payable_id_index ON payments (payable_type, payable_id)');

        Schema::enableForeignKeyConstraints();
    }
};
