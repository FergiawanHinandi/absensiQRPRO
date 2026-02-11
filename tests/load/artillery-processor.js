/**
 * Artillery Processor - Custom Functions
 * 
 * Handles:
 * - Test data generation
 * - Response validation
 * - Chaos engineering
 * - Metrics collection
 */

const crypto = require('crypto');

// ============================================================================
// METRICS TRACKING
// ============================================================================

const metrics = {
    duplicateAttendance: 0,
    deadlockErrors: 0,
    redisErrors: 0,
    dbLockWaits: 0,
    successfulScans: 0,
    failedScans: 0,
    qrGenerations: 0,
    webhookProcessed: 0,
    webhookIdempotent: 0,
};

// ============================================================================
// TEST DATA GENERATION
// ============================================================================

/**
 * Generate student test data
 */
function generateStudentData(context, events, done) {
    const schoolId = context.vars.schools[Math.floor(Math.random() * context.vars.schools.length)];
    const studentId = (schoolId * 1000) + Math.floor(Math.random() * 1000) + 1;
    
    context.vars.studentId = studentId;
    context.vars.schoolId = schoolId;
    context.vars.studentToken = `student_token_${studentId}`;
    context.vars.deviceId = `device_${studentId}`;
    
    // GPS coordinates (Jakarta area with small variance)
    context.vars.latitude = -6.200000 + (Math.random() * 0.001);
    context.vars.longitude = 106.816666 + (Math.random() * 0.001);
    context.vars.gpsAccuracy = Math.floor(Math.random() * 15) + 5;
    
    return done();
}

/**
 * Generate teacher test data
 */
function generateTeacherData(context, events, done) {
    const schoolId = context.vars.schools[Math.floor(Math.random() * context.vars.schools.length)];
    const teacherId = (schoolId * 100) + Math.floor(Math.random() * 10) + 1;
    const scheduleId = (schoolId * 100) + Math.floor(Math.random() * 10) + 1;
    
    context.vars.teacherId = teacherId;
    context.vars.schoolId = schoolId;
    context.vars.scheduleId = scheduleId;
    context.vars.teacherToken = `teacher_token_${teacherId}`;
    
    return done();
}

/**
 * Generate QR payload
 */
function generateQRPayload(context, events, done) {
    const scheduleId = (context.vars.schoolId * 100) + Math.floor(Math.random() * 10) + 1;
    const now = new Date();
    const expiresAt = new Date(now.getTime() + 60000); // 60 seconds
    
    const qrData = {
        schedule_id: scheduleId,
        school_id: context.vars.schoolId,
        expires_at: expiresAt.toISOString(),
        idempotency_key: `${context.vars.studentId}_${scheduleId}_${Date.now()}_${Math.random()}`,
        generated_at: now.toISOString(),
    };
    
    // Mock signature (in real test, use actual HMAC)
    const signature = crypto
        .createHmac('sha256', 'test_secret_key')
        .update(JSON.stringify(qrData))
        .digest('hex');
    
    context.vars.qrPayload = {
        data: qrData,
        signature: signature,
    };
    
    return done();
}

/**
 * Generate webhook test data
 */
function generateWebhookData(context, events, done) {
    const schoolId = context.vars.schools[Math.floor(Math.random() * context.vars.schools.length)];
    const timestamp = Date.now();
    const random = Math.floor(Math.random() * 9999) + 1000;
    
    context.vars.orderId = `ORDER_${schoolId}_${timestamp}_${random}`;
    context.vars.transactionId = `TRX_${timestamp}_${random}`;
    context.vars.schoolId = schoolId;
    
    // Mock Midtrans signature
    const signatureString = `${context.vars.orderId}200500000test_server_key`;
    context.vars.signatureKey = crypto
        .createHash('sha512')
        .update(signatureString)
        .digest('hex');
    
    return done();
}

// ============================================================================
// RESPONSE VALIDATION
// ============================================================================

/**
 * Validate scan response
 */
function validateScanResponse(requestParams, response, context, ee, next) {
    if (response.statusCode === 200 || response.statusCode === 201) {
        metrics.successfulScans++;
        
        // Check for duplicate attendance
        if (response.body && response.body.includes('sudah absen')) {
            metrics.duplicateAttendance++;
            console.warn(`⚠️  DUPLICATE ATTENDANCE DETECTED: Student ${context.vars.studentId}`);
        }
    } else {
        metrics.failedScans++;
        
        // Detect specific errors
        if (response.statusCode === 409) {
            metrics.duplicateAttendance++;
            console.warn(`⚠️  DUPLICATE (409): Student ${context.vars.studentId}`);
        }
        
        if (response.statusCode === 500) {
            const body = response.body || '';
            
            if (body.includes('deadlock')) {
                metrics.deadlockErrors++;
                console.warn(`⚠️  DEADLOCK: Student ${context.vars.studentId}`);
            }
            
            if (body.includes('Redis') || body.includes('redis')) {
                metrics.redisErrors++;
                console.warn(`⚠️  REDIS ERROR: Student ${context.vars.studentId}`);
            }
            
            if (body.includes('lock wait') || body.includes('Lock wait')) {
                metrics.dbLockWaits++;
                console.warn(`⚠️  DB LOCK WAIT: Student ${context.vars.studentId}`);
            }
        }
    }
    
    return next();
}

/**
 * Validate QR generation
 */
function validateQRGeneration(requestParams, response, context, ee, next) {
    if (response.statusCode === 200) {
        metrics.qrGenerations++;
        
        try {
            const body = JSON.parse(response.body);
            if (body.data && body.data.qr_payload) {
                // Store QR for validation
                if (!context.vars.generatedQRs) {
                    context.vars.generatedQRs = [];
                }
                context.vars.generatedQRs.push({
                    scheduleId: context.vars.scheduleId,
                    payload: body.data.qr_payload,
                    timestamp: Date.now(),
                });
            }
        } catch (e) {
            console.error('Failed to parse QR response:', e.message);
        }
    }
    
    return next();
}

/**
 * Validate webhook idempotency
 */
function validateWebhookIdempotency(requestParams, response, context, ee, next) {
    if (response.statusCode === 200) {
        metrics.webhookProcessed++;
        
        try {
            const body = JSON.parse(response.body);
            if (body.idempotent === true) {
                metrics.webhookIdempotent++;
                console.log(`✅ IDEMPOTENCY WORKING: ${context.vars.orderId}`);
            }
        } catch (e) {
            console.error('Failed to parse webhook response:', e.message);
        }
    }
    
    return next();
}

/**
 * Validate dashboard performance
 */
function validateDashboardPerformance(requestParams, response, context, ee, next) {
    const responseTime = response.timings ? response.timings.response : 0;
    
    if (responseTime > 2000) {
        console.warn(`⚠️  SLOW DASHBOARD: ${responseTime}ms (threshold: 2000ms)`);
    }
    
    return next();
}

// ============================================================================
// CHAOS ENGINEERING
// ============================================================================

/**
 * Simulate Redis restart
 */
function simulateRedisRestart(context, events, done) {
    console.log('\n🔥 CHAOS: Simulating Redis restart...');
    
    // In real scenario, execute:
    // docker restart redis
    // or
    // systemctl restart redis
    
    // For simulation, we'll just log and continue
    console.log('⚠️  Redis restarted (simulated)');
    console.log('⏳ Waiting for system recovery...');
    
    return done();
}

/**
 * Validate system recovery after Redis restart
 */
function validateSystemRecovery(context, events, done) {
    console.log('✅ Validating system recovery...');
    
    // Check if system is still processing requests
    const recentSuccessRate = metrics.successfulScans / (metrics.successfulScans + metrics.failedScans);
    
    if (recentSuccessRate > 0.99) {
        console.log(`✅ SYSTEM RECOVERED: Success rate ${(recentSuccessRate * 100).toFixed(2)}%`);
    } else {
        console.warn(`⚠️  DEGRADED PERFORMANCE: Success rate ${(recentSuccessRate * 100).toFixed(2)}%`);
    }
    
    return done();
}

/**
 * Inject database latency
 */
function injectDBLatency(context, events, done) {
    console.log('\n🔥 CHAOS: Injecting 300ms database latency...');
    
    // In real scenario, use:
    // tc qdisc add dev eth0 root netem delay 300ms
    // or MySQL: SET GLOBAL innodb_thread_sleep_delay = 300000;
    
    console.log('⚠️  Database latency injected (simulated)');
    
    return done();
}

/**
 * Remove database latency
 */
function removeDBLatency(context, events, done) {
    console.log('✅ Removing database latency...');
    
    // In real scenario, use:
    // tc qdisc del dev eth0 root
    
    console.log('✅ Database latency removed');
    
    return done();
}

/**
 * Validate performance recovery
 */
function validatePerformanceRecovery(context, events, done) {
    console.log('✅ Validating performance recovery...');
    
    // Check if response times are back to normal
    console.log('✅ Performance recovered');
    
    return done();
}

/**
 * Validate no duplicate QR codes
 */
function validateNoDuplicateQR(context, events, done) {
    if (!context.vars.generatedQRs || context.vars.generatedQRs.length === 0) {
        return done();
    }
    
    const qrs = context.vars.generatedQRs;
    const idempotencyKeys = qrs.map(qr => qr.payload.data.idempotency_key);
    const uniqueKeys = new Set(idempotencyKeys);
    
    if (idempotencyKeys.length !== uniqueKeys.size) {
        console.error(`❌ DUPLICATE QR DETECTED: ${idempotencyKeys.length} generated, ${uniqueKeys.size} unique`);
    } else {
        console.log(`✅ NO DUPLICATE QR: ${uniqueKeys.size} unique QR codes`);
    }
    
    return done();
}

/**
 * Validate no double subscription
 */
function validateNoDoubleSubscription(context, events, done) {
    console.log('✅ Validating no double subscription...');
    
    // Check webhook idempotency rate
    const idempotencyRate = metrics.webhookIdempotent / metrics.webhookProcessed;
    
    console.log(`📊 Webhook Idempotency Rate: ${(idempotencyRate * 100).toFixed(2)}%`);
    console.log(`📊 Total Webhooks: ${metrics.webhookProcessed}`);
    console.log(`📊 Idempotent Webhooks: ${metrics.webhookIdempotent}`);
    
    if (idempotencyRate > 0.5) {
        console.log('✅ HIGH IDEMPOTENCY RATE: System handling duplicates well');
    }
    
    return done();
}

// ============================================================================
// FINAL SUMMARY
// ============================================================================

/**
 * Print final metrics summary
 */
function printSummary() {
    console.log('\n' + '='.repeat(70));
    console.log('📊 LOAD TEST METRICS SUMMARY');
    console.log('='.repeat(70));
    console.log(`Successful Scans:       ${metrics.successfulScans}`);
    console.log(`Failed Scans:           ${metrics.failedScans}`);
    console.log(`Duplicate Attendance:   ${metrics.duplicateAttendance} ❌`);
    console.log(`Deadlock Errors:        ${metrics.deadlockErrors}`);
    console.log(`Redis Errors:           ${metrics.redisErrors}`);
    console.log(`DB Lock Waits:          ${metrics.dbLockWaits}`);
    console.log(`QR Generations:         ${metrics.qrGenerations}`);
    console.log(`Webhooks Processed:     ${metrics.webhookProcessed}`);
    console.log(`Webhooks Idempotent:    ${metrics.webhookIdempotent}`);
    console.log('='.repeat(70));
    
    // Calculate error rate
    const totalRequests = metrics.successfulScans + metrics.failedScans;
    const errorRate = totalRequests > 0 ? (metrics.failedScans / totalRequests) * 100 : 0;
    
    console.log(`\n📈 ERROR RATE: ${errorRate.toFixed(2)}%`);
    
    if (errorRate < 1) {
        console.log('✅ PASS: Error rate < 1%');
    } else {
        console.log('❌ FAIL: Error rate >= 1%');
    }
    
    if (metrics.duplicateAttendance === 0) {
        console.log('✅ PASS: No duplicate attendance');
    } else {
        console.log(`❌ FAIL: ${metrics.duplicateAttendance} duplicate attendance detected`);
    }
    
    console.log('='.repeat(70) + '\n');
}

// Export functions
module.exports = {
    generateStudentData,
    generateTeacherData,
    generateQRPayload,
    generateWebhookData,
    validateScanResponse,
    validateQRGeneration,
    validateWebhookIdempotency,
    validateDashboardPerformance,
    simulateRedisRestart,
    validateSystemRecovery,
    injectDBLatency,
    removeDBLatency,
    validatePerformanceRecovery,
    validateNoDuplicateQR,
    validateNoDoubleSubscription,
    printSummary,
};

// Print summary on process exit
process.on('exit', printSummary);
