# Blockchain-Based Attendance Proof Layer

## Executive Summary

This document defines a blockchain-based proof layer that provides immutable, tamper-proof attendance records while maintaining privacy and cost-efficiency through batched daily submissions.

**Key Benefits:**
- **Immutable Proof:** Attendance records cannot be altered retroactively
- **Tamper Detection:** Instant detection of any data manipulation
- **Privacy-Preserving:** Only cryptographic hashes stored on-chain
- **Cost-Efficient:** Batched daily submissions reduce gas fees by 99%
- **Lightweight:** Blockchain as proof layer, not primary storage

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                      APPLICATION LAYER                               │
│                                                                      │
│  ┌──────────────┐         ┌──────────────┐         ┌──────────────┐│
│  │   Mobile     │────────▶│   Laravel    │────────▶│    MySQL     ││
│  │     App      │         │   Backend    │         │   Database   ││
│  └──────────────┘         └──────┬───────┘         └──────────────┘│
│                                  │                                  │
└──────────────────────────────────┼──────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                        EVENT LAYER                                   │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │              AttendanceRecorded Event                           ││
│  │                                                                 ││
│  │  {                                                              ││
│  │    attendance_id: "att-123",                                   ││
│  │    student_id: "student-456",                                  ││
│  │    school_id: "school-789",                                    ││
│  │    timestamp: "2026-02-11T07:00:00Z",                          ││
│  │    status: "present"                                           ││
│  │  }                                                              ││
│  └────────────────────────┬───────────────────────────────────────┘│
│                           │                                         │
└───────────────────────────┼─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    PROOF GENERATION LAYER                            │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │              Hash Generation Service                            ││
│  │                                                                 ││
│  │  hash = SHA256(                                                ││
│  │    attendance_id +                                             ││
│  │    student_id +                                                ││
│  │    timestamp +                                                 ││
│  │    school_id +                                                 ││
│  │    status +                                                    ││
│  │    nonce                                                       ││
│  │  )                                                             ││
│  │                                                                 ││
│  │  → Store in pending_proofs table                              ││
│  └────────────────────────┬───────────────────────────────────────┘│
│                           │                                         │
└───────────────────────────┼─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    MERKLE TREE LAYER                                 │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │         Daily Merkle Tree Builder (End of Day)                 ││
│  │                                                                 ││
│  │  Collect all attendance hashes for school X on date Y         ││
│  │                                                                 ││
│  │         hash1    hash2    hash3    hash4                       ││
│  │            \      /          \      /                          ││
│  │             hash12            hash34                           ││
│  │                \              /                                ││
│  │                 merkle_root                                    ││
│  │                                                                 ││
│  │  → Generate Merkle proof for each attendance                  ││
│  └────────────────────────┬───────────────────────────────────────┘│
│                           │                                         │
└───────────────────────────┼─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    BLOCKCHAIN LAYER                                  │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │              Smart Contract (Polygon)                           ││
│  │                                                                 ││
│  │  function submitDailyProof(                                    ││
│  │    bytes32 merkleRoot,                                         ││
│  │    string schoolId,                                            ││
│  │    uint256 date,                                               ││
│  │    uint256 attendanceCount                                     ││
│  │  ) external onlyAuthorized                                     ││
│  │                                                                 ││
│  │  → Store merkle root on-chain                                 ││
│  │  → Emit ProofSubmitted event                                  ││
│  └────────────────────────┬───────────────────────────────────────┘│
│                           │                                         │
│  Options:                                                           │
│  • Polygon (Public, Low Cost)                                      │
│  • Hyperledger Fabric (Private Consortium)                         │
│  • Ethereum L2 (Optimism, Arbitrum)                                │
└───────────────────────────┼─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    VERIFICATION LAYER                                │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │              Tamper Detection Service                           ││
│  │                                                                 ││
│  │  1. Recompute hash from database record                       ││
│  │  2. Get Merkle proof from local storage                       ││
│  │  3. Verify against on-chain Merkle root                       ││
│  │  4. If mismatch → TAMPERING DETECTED                          ││
│  └────────────────────────────────────────────────────────────────┘│
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Hash Generation Flow

### Attendance Hash Structure

```javascript
// Hash components
const hashInput = {
    attendance_id: "att-20260211-001",
    student_id: "student-456",
    school_id: "school-789",
    timestamp: "2026-02-11T07:00:00Z",
    status: "present",
    nonce: "random-nonce-123",  // Prevent rainbow table attacks
    version: "v1"  // For future schema changes
};

// Canonical JSON serialization (deterministic)
const canonical = JSON.stringify(hashInput, Object.keys(hashInput).sort());

// SHA-256 hash
const hash = SHA256(canonical);
// Result: 0x1a2b3c4d5e6f7890abcdef1234567890abcdef1234567890abcdef1234567890
```

### Implementation (Laravel)

```php
<?php
// app/Services/Blockchain/AttendanceHashService.php

namespace App\Services\Blockchain;

use App\Models\Attendance;
use Illuminate\Support\Facades\Log;

class AttendanceHashService
{
    /**
     * Generate cryptographic hash for attendance record
     */
    public function generateHash(Attendance $attendance): string
    {
        // Prepare hash input
        $hashInput = [
            'attendance_id' => $attendance->id,
            'student_id' => $attendance->student_id,
            'school_id' => $attendance->school_id,
            'timestamp' => $attendance->check_in_time->toIso8601String(),
            'status' => $attendance->status,
            'nonce' => $attendance->nonce ?? $this->generateNonce(),
            'version' => 'v1',
        ];
        
        // Sort keys for deterministic serialization
        ksort($hashInput);
        
        // Canonical JSON
        $canonical = json_encode($hashInput, JSON_UNESCAPED_SLASHES);
        
        // SHA-256 hash
        $hash = hash('sha256', $canonical);
        
        Log::info('Generated attendance hash', [
            'attendance_id' => $attendance->id,
            'hash' => $hash,
        ]);
        
        return $hash;
    }
    
    /**
     * Generate random nonce
     */
    private function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * Verify attendance hash
     */
    public function verifyHash(Attendance $attendance, string $expectedHash): bool
    {
        $computedHash = $this->generateHash($attendance);
        
        if ($computedHash !== $expectedHash) {
            Log::critical('Attendance hash mismatch - TAMPERING DETECTED', [
                'attendance_id' => $attendance->id,
                'expected_hash' => $expectedHash,
                'computed_hash' => $computedHash,
            ]);
            
            return false;
        }
        
        return true;
    }
}
```

---

## Merkle Tree Structure

### Daily Merkle Tree

```
School: school-789
Date: 2026-02-11

Attendance Records:
├─ att-001: hash1 = 0x1a2b...
├─ att-002: hash2 = 0x3c4d...
├─ att-003: hash3 = 0x5e6f...
└─ att-004: hash4 = 0x7890...

Merkle Tree:
                    ROOT
                 0xabcd1234
                 /        \
            0x1234abcd    0x5678efab
             /      \      /      \
         hash1    hash2  hash3  hash4
       0x1a2b   0x3c4d  0x5e6f  0x7890

Merkle Proof for att-001:
├─ hash2 (sibling)
├─ 0x5678efab (parent's sibling)
└─ ROOT (verify against on-chain)
```

### Merkle Tree Implementation

```php
<?php
// app/Services/Blockchain/MerkleTreeService.php

namespace App\Services\Blockchain;

class MerkleTreeService
{
    /**
     * Build Merkle tree from attendance hashes
     */
    public function buildTree(array $hashes): array
    {
        if (empty($hashes)) {
            throw new \InvalidArgumentException('Cannot build tree from empty hashes');
        }
        
        // Ensure even number of hashes (duplicate last if odd)
        if (count($hashes) % 2 !== 0) {
            $hashes[] = end($hashes);
        }
        
        $tree = [$hashes];
        $currentLevel = $hashes;
        
        // Build tree bottom-up
        while (count($currentLevel) > 1) {
            $nextLevel = [];
            
            for ($i = 0; $i < count($currentLevel); $i += 2) {
                $left = $currentLevel[$i];
                $right = $currentLevel[$i + 1] ?? $left;
                
                $parent = $this->hashPair($left, $right);
                $nextLevel[] = $parent;
            }
            
            $tree[] = $nextLevel;
            $currentLevel = $nextLevel;
        }
        
        return $tree;
    }
    
    /**
     * Get Merkle root
     */
    public function getRoot(array $tree): string
    {
        return end($tree)[0];
    }
    
    /**
     * Generate Merkle proof for a specific hash
     */
    public function generateProof(array $tree, int $index): array
    {
        $proof = [];
        $currentIndex = $index;
        
        // Traverse from leaf to root
        for ($level = 0; $level < count($tree) - 1; $level++) {
            $isRightNode = $currentIndex % 2 === 1;
            $siblingIndex = $isRightNode ? $currentIndex - 1 : $currentIndex + 1;
            
            if (isset($tree[$level][$siblingIndex])) {
                $proof[] = [
                    'hash' => $tree[$level][$siblingIndex],
                    'position' => $isRightNode ? 'left' : 'right',
                ];
            }
            
            $currentIndex = intdiv($currentIndex, 2);
        }
        
        return $proof;
    }
    
    /**
     * Verify Merkle proof
     */
    public function verifyProof(string $leaf, array $proof, string $root): bool
    {
        $computedHash = $leaf;
        
        foreach ($proof as $step) {
            if ($step['position'] === 'left') {
                $computedHash = $this->hashPair($step['hash'], $computedHash);
            } else {
                $computedHash = $this->hashPair($computedHash, $step['hash']);
            }
        }
        
        return $computedHash === $root;
    }
    
    /**
     * Hash a pair of nodes
     */
    private function hashPair(string $left, string $right): string
    {
        // Sort to ensure deterministic ordering
        $sorted = [$left, $right];
        sort($sorted);
        
        return hash('sha256', $sorted[0] . $sorted[1]);
    }
}
```

---

## Smart Contract

### Solidity Smart Contract (Polygon)

```solidity
// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

/**
 * @title AttendanceProofRegistry
 * @dev Store daily Merkle roots for attendance proofs
 */
contract AttendanceProofRegistry {
    
    // Struct for daily proof
    struct DailyProof {
        bytes32 merkleRoot;
        string schoolId;
        uint256 date;  // Unix timestamp (start of day)
        uint256 attendanceCount;
        uint256 blockNumber;
        uint256 timestamp;
        address submitter;
    }
    
    // Mapping: school_id => date => DailyProof
    mapping(string => mapping(uint256 => DailyProof)) public proofs;
    
    // Authorized submitters (backend servers)
    mapping(address => bool) public authorizedSubmitters;
    
    // Contract owner
    address public owner;
    
    // Events
    event ProofSubmitted(
        string indexed schoolId,
        uint256 indexed date,
        bytes32 merkleRoot,
        uint256 attendanceCount,
        address submitter
    );
    
    event SubmitterAuthorized(address indexed submitter);
    event SubmitterRevoked(address indexed submitter);
    
    // Modifiers
    modifier onlyOwner() {
        require(msg.sender == owner, "Only owner can call this");
        _;
    }
    
    modifier onlyAuthorized() {
        require(authorizedSubmitters[msg.sender], "Not authorized");
        _;
    }
    
    constructor() {
        owner = msg.sender;
        authorizedSubmitters[msg.sender] = true;
    }
    
    /**
     * @dev Submit daily attendance proof
     * @param merkleRoot Merkle root of all attendance hashes for the day
     * @param schoolId School identifier
     * @param date Unix timestamp (start of day)
     * @param attendanceCount Number of attendance records
     */
    function submitDailyProof(
        bytes32 merkleRoot,
        string memory schoolId,
        uint256 date,
        uint256 attendanceCount
    ) external onlyAuthorized {
        require(merkleRoot != bytes32(0), "Invalid Merkle root");
        require(bytes(schoolId).length > 0, "Invalid school ID");
        require(attendanceCount > 0, "Invalid attendance count");
        
        // Prevent overwriting existing proof
        require(
            proofs[schoolId][date].merkleRoot == bytes32(0),
            "Proof already exists for this date"
        );
        
        // Store proof
        proofs[schoolId][date] = DailyProof({
            merkleRoot: merkleRoot,
            schoolId: schoolId,
            date: date,
            attendanceCount: attendanceCount,
            blockNumber: block.number,
            timestamp: block.timestamp,
            submitter: msg.sender
        });
        
        emit ProofSubmitted(
            schoolId,
            date,
            merkleRoot,
            attendanceCount,
            msg.sender
        );
    }
    
    /**
     * @dev Get proof for a specific school and date
     */
    function getProof(
        string memory schoolId,
        uint256 date
    ) external view returns (DailyProof memory) {
        return proofs[schoolId][date];
    }
    
    /**
     * @dev Verify if a Merkle root exists
     */
    function verifyProofExists(
        string memory schoolId,
        uint256 date,
        bytes32 merkleRoot
    ) external view returns (bool) {
        return proofs[schoolId][date].merkleRoot == merkleRoot;
    }
    
    /**
     * @dev Authorize a new submitter
     */
    function authorizeSubmitter(address submitter) external onlyOwner {
        authorizedSubmitters[submitter] = true;
        emit SubmitterAuthorized(submitter);
    }
    
    /**
     * @dev Revoke submitter authorization
     */
    function revokeSubmitter(address submitter) external onlyOwner {
        authorizedSubmitters[submitter] = false;
        emit SubmitterRevoked(submitter);
    }
}
```

### Smart Contract Interaction (Laravel)

```php
<?php
// app/Services/Blockchain/BlockchainService.php

namespace App\Services\Blockchain;

use Web3\Web3;
use Web3\Contract;
use Illuminate\Support\Facades\Log;

class BlockchainService
{
    private $web3;
    private $contract;
    private $contractAddress;
    private $privateKey;
    
    public function __construct()
    {
        $this->web3 = new Web3(config('blockchain.rpc_url'));
        $this->contractAddress = config('blockchain.contract_address');
        $this->privateKey = config('blockchain.private_key');
        
        $abi = json_decode(file_get_contents(storage_path('blockchain/abi.json')), true);
        $this->contract = new Contract($this->web3->provider, $abi);
    }
    
    /**
     * Submit daily proof to blockchain
     */
    public function submitDailyProof(
        string $merkleRoot,
        string $schoolId,
        int $date,
        int $attendanceCount
    ): ?string {
        try {
            // Prepare transaction
            $data = $this->contract->at($this->contractAddress)->getData(
                'submitDailyProof',
                $merkleRoot,
                $schoolId,
                $date,
                $attendanceCount
            );
            
            // Send transaction
            $txHash = $this->sendTransaction($data);
            
            Log::info('Daily proof submitted to blockchain', [
                'school_id' => $schoolId,
                'date' => $date,
                'merkle_root' => $merkleRoot,
                'tx_hash' => $txHash,
            ]);
            
            return $txHash;
            
        } catch (\Exception $e) {
            Log::error('Failed to submit proof to blockchain', [
                'error' => $e->getMessage(),
                'school_id' => $schoolId,
                'date' => $date,
            ]);
            
            return null;
        }
    }
    
    /**
     * Get proof from blockchain
     */
    public function getProof(string $schoolId, int $date): ?array
    {
        try {
            $this->contract->at($this->contractAddress)->call(
                'getProof',
                $schoolId,
                $date,
                function ($err, $result) use (&$proof) {
                    if ($err) {
                        throw new \Exception($err->getMessage());
                    }
                    $proof = $result;
                }
            );
            
            return $proof;
            
        } catch (\Exception $e) {
            Log::error('Failed to get proof from blockchain', [
                'error' => $e->getMessage(),
                'school_id' => $schoolId,
                'date' => $date,
            ]);
            
            return null;
        }
    }
    
    /**
     * Send transaction to blockchain
     */
    private function sendTransaction(string $data): string
    {
        // Implementation depends on Web3 library
        // This is a simplified example
        
        $account = $this->web3->eth->accounts->privateKeyToAccount($this->privateKey);
        
        $tx = [
            'from' => $account->address,
            'to' => $this->contractAddress,
            'data' => $data,
            'gas' => 200000,
            'gasPrice' => '30000000000', // 30 Gwei
        ];
        
        $signedTx = $account->signTransaction($tx);
        
        $txHash = null;
        $this->web3->eth->sendRawTransaction(
            $signedTx->rawTransaction,
            function ($err, $hash) use (&$txHash) {
                if ($err) {
                    throw new \Exception($err->getMessage());
                }
                $txHash = $hash;
            }
        );
        
        return $txHash;
    }
}
```

---

## Batching Strategy

### Daily Batch Job

```php
<?php
// app/Console/Commands/SubmitDailyProofs.php

namespace App\Console\Commands;

use App\Services\Blockchain\AttendanceHashService;
use App\Services\Blockchain\MerkleTreeService;
use App\Services\Blockchain\BlockchainService;
use App\Models\Attendance;
use App\Models\BlockchainProof;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SubmitDailyProofs extends Command
{
    protected $signature = 'blockchain:submit-daily-proofs {--date=}';
    protected $description = 'Submit daily attendance proofs to blockchain';
    
    private $hashService;
    private $merkleService;
    private $blockchainService;
    
    public function __construct(
        AttendanceHashService $hashService,
        MerkleTreeService $merkleService,
        BlockchainService $blockchainService
    ) {
        parent::__construct();
        $this->hashService = $hashService;
        $this->merkleService = $merkleService;
        $this->blockchainService = $blockchainService;
    }
    
    public function handle()
    {
        $date = $this->option('date') 
            ? Carbon::parse($this->option('date'))
            : Carbon::yesterday();
        
        $this->info("Submitting proofs for date: {$date->toDateString()}");
        
        // Get all schools with attendance on this date
        $schools = Attendance::whereDate('check_in_time', $date)
            ->distinct('school_id')
            ->pluck('school_id');
        
        $this->info("Found {$schools->count()} schools with attendance");
        
        $bar = $this->output->createProgressBar($schools->count());
        
        foreach ($schools as $schoolId) {
            try {
                $this->submitSchoolProof($schoolId, $date);
                $bar->advance();
            } catch (\Exception $e) {
                $this->error("Failed to submit proof for school {$schoolId}: {$e->getMessage()}");
            }
        }
        
        $bar->finish();
        $this->info("\nDone!");
    }
    
    private function submitSchoolProof(string $schoolId, Carbon $date)
    {
        // Get all attendance records for this school on this date
        $attendances = Attendance::where('school_id', $schoolId)
            ->whereDate('check_in_time', $date)
            ->orderBy('id')
            ->get();
        
        if ($attendances->isEmpty()) {
            return;
        }
        
        // Generate hashes
        $hashes = [];
        foreach ($attendances as $attendance) {
            $hash = $this->hashService->generateHash($attendance);
            $hashes[] = $hash;
            
            // Store hash in database
            $attendance->update(['proof_hash' => $hash]);
        }
        
        // Build Merkle tree
        $tree = $this->merkleService->buildTree($hashes);
        $merkleRoot = $this->merkleService->getRoot($tree);
        
        // Generate proofs for each attendance
        foreach ($attendances as $index => $attendance) {
            $proof = $this->merkleService->generateProof($tree, $index);
            
            // Store Merkle proof
            BlockchainProof::create([
                'attendance_id' => $attendance->id,
                'hash' => $hashes[$index],
                'merkle_proof' => json_encode($proof),
                'merkle_root' => $merkleRoot,
                'date' => $date->toDateString(),
            ]);
        }
        
        // Submit to blockchain
        $txHash = $this->blockchainService->submitDailyProof(
            merkleRoot: '0x' . $merkleRoot,
            schoolId: $schoolId,
            date: $date->startOfDay()->timestamp,
            attendanceCount: $attendances->count()
        );
        
        if ($txHash) {
            $this->info("Submitted proof for school {$schoolId}: {$txHash}");
        } else {
            throw new \Exception("Failed to submit proof to blockchain");
        }
    }
}
```

### Scheduled Task

```php
// app/Console/Kernel.php

protected function schedule(Schedule $schedule)
{
    // Submit daily proofs at 1 AM (after all attendance for previous day is recorded)
    $schedule->command('blockchain:submit-daily-proofs')
        ->dailyAt('01:00')
        ->timezone('Asia/Jakarta');
}
```

---

## Cost Estimation

### Blockchain Options Comparison

| Option | Type | Cost per Transaction | Monthly Cost (1,000 schools) | Pros | Cons |
|--------|------|---------------------|------------------------------|------|------|
| **Polygon** | Public L2 | $0.001 - $0.01 | $30 - $300 | Low cost, public verification | Requires MATIC tokens |
| **Ethereum L2 (Optimism)** | Public L2 | $0.01 - $0.10 | $300 - $3,000 | Ethereum security | Higher cost than Polygon |
| **Hyperledger Fabric** | Private | $0 (infrastructure cost) | $500 - $1,000 | Full control, no gas fees | Setup complexity |
| **Ethereum Mainnet** | Public L1 | $1 - $50 | $30,000 - $1.5M | Maximum security | Prohibitively expensive |

### Recommended: Polygon

**Cost Breakdown (1,000 schools):**

```
Daily Transactions: 1,000 schools × 1 transaction = 1,000 tx/day
Monthly Transactions: 1,000 × 30 = 30,000 tx/month

Gas Cost per Transaction:
- submitDailyProof: ~100,000 gas
- Gas Price: 30 Gwei (average)
- Cost: 100,000 × 30 × 10^-9 = 0.003 MATIC
- USD Cost: 0.003 × $0.50 = $0.0015

Monthly Cost:
30,000 tx × $0.0015 = $45/month

Annual Cost: $540/year
```

**Cost Optimization:**
- Batch multiple schools in single transaction: Reduce to $15/month
- Use Polygon's low gas periods: Reduce by 50%
- **Optimized Cost: $10-20/month**

---

## Privacy & Security

### Privacy-Preserving Design

**What is stored on-chain:**
```json
{
  "merkleRoot": "0xabcd1234...",
  "schoolId": "school-789",
  "date": 1707609600,
  "attendanceCount": 150
}
```

**What is NOT stored on-chain:**
- ❌ Student names
- ❌ Student IDs
- ❌ Personal information
- ❌ Individual attendance hashes

**Privacy Guarantees:**
- Only Merkle root is public
- Individual hashes stored off-chain
- Cannot reverse-engineer student data from Merkle root
- Verification requires both on-chain root and off-chain proof

### Security Measures

```php
// app/Services/Blockchain/TamperDetectionService.php

class TamperDetectionService
{
    public function detectTampering(Attendance $attendance): array
    {
        $result = [
            'is_tampered' => false,
            'checks' => [],
        ];
        
        // Check 1: Hash verification
        $storedHash = $attendance->proof_hash;
        $computedHash = $this->hashService->generateHash($attendance);
        
        $result['checks']['hash_match'] = ($storedHash === $computedHash);
        
        if (!$result['checks']['hash_match']) {
            $result['is_tampered'] = true;
            $result['tampering_type'] = 'database_modification';
            return $result;
        }
        
        // Check 2: Merkle proof verification
        $proof = BlockchainProof::where('attendance_id', $attendance->id)->first();
        
        if (!$proof) {
            $result['checks']['proof_exists'] = false;
            $result['is_tampered'] = true;
            $result['tampering_type'] = 'missing_proof';
            return $result;
        }
        
        $merkleProof = json_decode($proof->merkle_proof, true);
        $isValid = $this->merkleService->verifyProof(
            $computedHash,
            $merkleProof,
            $proof->merkle_root
        );
        
        $result['checks']['merkle_proof_valid'] = $isValid;
        
        if (!$isValid) {
            $result['is_tampered'] = true;
            $result['tampering_type'] = 'invalid_merkle_proof';
            return $result;
        }
        
        // Check 3: Blockchain verification
        $onChainProof = $this->blockchainService->getProof(
            $attendance->school_id,
            Carbon::parse($proof->date)->startOfDay()->timestamp
        );
        
        $result['checks']['blockchain_match'] = (
            $onChainProof &&
            $onChainProof['merkleRoot'] === '0x' . $proof->merkle_root
        );
        
        if (!$result['checks']['blockchain_match']) {
            $result['is_tampered'] = true;
            $result['tampering_type'] = 'blockchain_mismatch';
            return $result;
        }
        
        return $result;
    }
}
```

---

## Failure Handling

### Pending Proofs Queue

```php
<?php
// app/Models/PendingBlockchainProof.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingBlockchainProof extends Model
{
    protected $fillable = [
        'school_id',
        'date',
        'merkle_root',
        'attendance_count',
        'retry_count',
        'last_error',
        'status',
    ];
    
    protected $casts = [
        'date' => 'date',
        'retry_count' => 'integer',
    ];
}
```

### Retry Mechanism

```php
<?php
// app/Console/Commands/RetryPendingProofs.php

class RetryPendingProofs extends Command
{
    protected $signature = 'blockchain:retry-pending';
    protected $description = 'Retry submitting pending blockchain proofs';
    
    public function handle()
    {
        $pending = PendingBlockchainProof::where('status', 'pending')
            ->where('retry_count', '<', 5)
            ->get();
        
        foreach ($pending as $proof) {
            try {
                $txHash = $this->blockchainService->submitDailyProof(
                    merkleRoot: $proof->merkle_root,
                    schoolId: $proof->school_id,
                    date: $proof->date->timestamp,
                    attendanceCount: $proof->attendance_count
                );
                
                if ($txHash) {
                    $proof->update([
                        'status' => 'submitted',
                        'tx_hash' => $txHash,
                    ]);
                    
                    $this->info("Submitted proof: {$txHash}");
                } else {
                    throw new \Exception('Submission failed');
                }
                
            } catch (\Exception $e) {
                $proof->increment('retry_count');
                $proof->update([
                    'last_error' => $e->getMessage(),
                    'status' => $proof->retry_count >= 5 ? 'failed' : 'pending',
                ]);
                
                $this->error("Failed: {$e->getMessage()}");
            }
        }
    }
}
```

### Scheduled Retry

```php
// app/Console/Kernel.php

protected function schedule(Schedule $schedule)
{
    // Retry pending proofs every hour
    $schedule->command('blockchain:retry-pending')
        ->hourly();
}
```

---

## Legal Implications

### Legal Compliance Note

```markdown
# Legal Implications of Blockchain-Based Attendance Proof

## 1. Data Protection & Privacy

### GDPR Compliance
- **Right to be Forgotten:** Blockchain is immutable. Only hashes (not personal data) 
  are stored on-chain to comply with GDPR Article 17.
- **Data Minimization:** Only cryptographic hashes stored, no PII on blockchain.
- **Lawful Basis:** Legitimate interest in preventing fraud and ensuring data integrity.

### Indonesian Data Protection (UU PDP)
- **Personal Data:** Student names/IDs NOT stored on blockchain.
- **Consent:** Schools must inform students/parents about blockchain usage.
- **Data Controller:** School remains data controller, blockchain is technical measure.

## 2. Evidence & Legal Validity

### Admissibility in Court
- **Electronic Evidence:** Blockchain proofs may be admissible under:
  - Indonesia: UU ITE (Electronic Information and Transactions)
  - International: eIDAS Regulation (EU)
- **Chain of Custody:** Immutable blockchain provides strong chain of custody.
- **Expert Testimony:** May require blockchain expert to explain verification process.

### Audit & Compliance
- **Regulatory Audits:** Blockchain provides tamper-proof audit trail.
- **School Inspections:** Inspectors can verify attendance records independently.
- **Dispute Resolution:** Immutable proof helps resolve attendance disputes.

## 3. Liability & Responsibility

### Smart Contract Risks
- **Code Bugs:** Smart contract bugs could prevent proof submission.
- **Mitigation:** Thorough auditing, insurance, fallback mechanisms.

### Data Integrity
- **Blockchain Proof:** Proves data existed at specific time, unchanged since.
- **Limitation:** Does not prove attendance actually occurred (only that record was created).

## 4. Cross-Border Considerations

### International Schools
- **Data Residency:** Blockchain data distributed globally.
- **Compliance:** Ensure compliance with local data protection laws.

## 5. Recommendations

1. **Privacy Policy Update:** Inform users about blockchain usage.
2. **Consent Forms:** Obtain consent for blockchain-based proof system.
3. **Legal Review:** Have legal team review implementation.
4. **Insurance:** Consider cyber insurance for smart contract risks.
5. **Documentation:** Maintain clear documentation of blockchain architecture.

## 6. Disclaimer

This blockchain proof system is designed as a TECHNICAL MEASURE for data integrity.
It does NOT replace legal requirements for record-keeping, data protection, or 
student privacy. Schools must ensure compliance with all applicable laws and regulations.

**Consult legal counsel before deployment.**
```

---

## Database Schema

```sql
-- Blockchain proofs table
CREATE TABLE blockchain_proofs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_id BIGINT UNSIGNED NOT NULL,
    hash VARCHAR(64) NOT NULL,
    merkle_proof JSON NOT NULL,
    merkle_root VARCHAR(64) NOT NULL,
    date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (attendance_id) REFERENCES attendances(id) ON DELETE CASCADE,
    INDEX idx_attendance_id (attendance_id),
    INDEX idx_date (date),
    INDEX idx_merkle_root (merkle_root)
);

-- Pending proofs table
CREATE TABLE pending_blockchain_proofs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id VARCHAR(255) NOT NULL,
    date DATE NOT NULL,
    merkle_root VARCHAR(64) NOT NULL,
    attendance_count INT NOT NULL,
    retry_count INT DEFAULT 0,
    last_error TEXT NULL,
    status ENUM('pending', 'submitted', 'failed') DEFAULT 'pending',
    tx_hash VARCHAR(66) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_status (status),
    INDEX idx_school_date (school_id, date),
    UNIQUE KEY unique_school_date (school_id, date)
);

-- Add proof_hash column to attendances table
ALTER TABLE attendances 
ADD COLUMN proof_hash VARCHAR(64) NULL AFTER nonce,
ADD INDEX idx_proof_hash (proof_hash);
```

---

## Implementation Checklist

### Phase 1: Setup (Week 1-2)
- [ ] Deploy smart contract to Polygon testnet
- [ ] Set up Web3 integration in Laravel
- [ ] Create database tables
- [ ] Implement hash generation service
- [ ] Test hash generation and verification

### Phase 2: Merkle Tree (Week 3)
- [ ] Implement Merkle tree builder
- [ ] Implement proof generation
- [ ] Implement proof verification
- [ ] Unit tests for Merkle operations

### Phase 3: Blockchain Integration (Week 4)
- [ ] Implement blockchain service
- [ ] Test smart contract interaction
- [ ] Implement daily batch job
- [ ] Test end-to-end flow on testnet

### Phase 4: Production (Week 5-6)
- [ ] Deploy smart contract to Polygon mainnet
- [ ] Configure production environment
- [ ] Run pilot with 10 schools
- [ ] Monitor and optimize
- [ ] Full rollout

---

## Conclusion

This blockchain-based attendance proof layer provides:

✅ **Immutable Proof:** Attendance records cannot be altered retroactively  
✅ **Tamper Detection:** Instant detection of any data manipulation  
✅ **Privacy-Preserving:** Only hashes stored on-chain, no PII  
✅ **Cost-Efficient:** $10-20/month for 1,000 schools  
✅ **Lightweight:** Blockchain as proof layer, not primary storage  
✅ **Legally Compliant:** GDPR and UU PDP compliant design  

The system is ready for implementation with Polygon as the recommended blockchain platform.
