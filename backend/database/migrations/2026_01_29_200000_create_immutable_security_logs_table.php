<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates an immutable security log table with hash chaining
     * for tamper-proof audit trail.
     */
    public function up(): void
    {
        Schema::create('immutable_security_logs', function (Blueprint $table) {
            $table->id();
            
            // Event information
            $table->string('event_type', 100)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->json('metadata')->nullable();
            
            // Hash chain fields - CRITICAL for integrity
            $table->char('previous_hash', 64)->index(); // SHA256 produces 64 hex chars
            $table->char('current_hash', 64)->unique(); // Must be unique to detect duplicates
            
            // Timestamp - no updated_at since records are immutable
            $table->timestamp('created_at')->useCurrent();
            
            // Additional integrity fields
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedBigInteger('sequence_number')->unique(); // Sequential ordering
            
            // Indexes for efficient querying
            $table->index(['school_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index('sequence_number');
        });

        // Create database-level protection against updates and deletes
        // This uses database triggers for additional security layer
        $this->createDatabaseTriggers();
        
        // Insert genesis block (first record in the chain)
        $this->insertGenesisBlock();
    }

    /**
     * Create database triggers to prevent UPDATE and DELETE operations.
     * This provides database-level enforcement even if application code is compromised.
     */
    protected function createDatabaseTriggers(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL triggers
            DB::unprepared("
                CREATE OR REPLACE FUNCTION prevent_immutable_log_modification()
                RETURNS TRIGGER AS \$\$
                BEGIN
                    RAISE EXCEPTION 'immutable_security_logs table does not allow UPDATE or DELETE operations';
                    RETURN NULL;
                END;
                \$\$ LANGUAGE plpgsql;

                CREATE TRIGGER prevent_update_immutable_logs
                BEFORE UPDATE ON immutable_security_logs
                FOR EACH ROW
                EXECUTE FUNCTION prevent_immutable_log_modification();

                CREATE TRIGGER prevent_delete_immutable_logs
                BEFORE DELETE ON immutable_security_logs
                FOR EACH ROW
                EXECUTE FUNCTION prevent_immutable_log_modification();
            ");
        } elseif ($driver === 'mysql') {
            // MySQL triggers
            DB::unprepared("
                CREATE TRIGGER prevent_update_immutable_logs
                BEFORE UPDATE ON immutable_security_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'immutable_security_logs table does not allow UPDATE operations';
                END;
            ");
            
            DB::unprepared("
                CREATE TRIGGER prevent_delete_immutable_logs
                BEFORE DELETE ON immutable_security_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'immutable_security_logs table does not allow DELETE operations';
                END;
            ");
        }
        // SQLite doesn't support BEFORE triggers that can prevent operations,
        // so we rely on application-level protection for SQLite
    }

    /**
     * Insert the genesis block - the first record in the hash chain.
     * This establishes the chain's starting point.
     */
    protected function insertGenesisBlock(): void
    {
        $genesisData = [
            'event_type' => 'GENESIS_BLOCK',
            'user_id' => null,
            'school_id' => null,
            'description' => 'Immutable security log chain initialized',
            'metadata' => json_encode([
                'version' => '1.0.0',
                'algorithm' => 'SHA256',
                'initialized_at' => now()->toIso8601String(),
            ]),
            'previous_hash' => str_repeat('0', 64), // Genesis has no previous
            'ip_address' => null,
            'user_agent' => 'System Initialization',
            'sequence_number' => 0,
            'created_at' => now(),
        ];

        // Calculate genesis block hash
        $hashInput = implode('|', [
            $genesisData['event_type'],
            '',  // user_id
            '',  // school_id
            $genesisData['description'],
            $genesisData['metadata'],
            $genesisData['previous_hash'],
            $genesisData['created_at']->format('Y-m-d H:i:s'), // Consistent format
            $genesisData['sequence_number'],
        ]);

        $genesisData['current_hash'] = hash('sha256', $hashInput);

        DB::table('immutable_security_logs')->insert($genesisData);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        // Drop triggers first
        if ($driver === 'pgsql') {
            DB::unprepared("
                DROP TRIGGER IF EXISTS prevent_update_immutable_logs ON immutable_security_logs;
                DROP TRIGGER IF EXISTS prevent_delete_immutable_logs ON immutable_security_logs;
                DROP FUNCTION IF EXISTS prevent_immutable_log_modification();
            ");
        } elseif ($driver === 'mysql') {
            DB::unprepared("DROP TRIGGER IF EXISTS prevent_update_immutable_logs");
            DB::unprepared("DROP TRIGGER IF EXISTS prevent_delete_immutable_logs");
        }

        Schema::dropIfExists('immutable_security_logs');
    }
};
