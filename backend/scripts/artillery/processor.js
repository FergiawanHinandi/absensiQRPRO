const { v4: uuidv4 } = require('uuid');

module.exports = {
    generateIdempotencyKey,
    generateCheckInPayload,
    logMetrics
};

function generateIdempotencyKey(userContext, events, done) {
    // Generate unique Idempotency Key per request
    const idempotencyKey = uuidv4();
    userContext.vars.idempotencyKey = idempotencyKey;
    return done();
}

function generateCheckInPayload(userContext, events, done) {
    // Generate random lat/lng around Jakarta
    const lat = -6.200000 + (Math.random() * 0.01 - 0.005);
    const lng = 106.816666 + (Math.random() * 0.01 - 0.005);

    userContext.vars.lat = lat;
    userContext.vars.lng = lng;
    userContext.vars.deviceId = `device-${uuidv4().substring(0, 8)}`;

    // Random student ID (Assuming IDs 1-100 exist in DB seeded)
    userContext.vars.studentId = Math.floor(Math.random() * 50) + 1;
    // Schedule ID (Assuming 1-10 exist)
    userContext.vars.scheduleId = 1;

    return done();
}

function logMetrics(requestParams, response, context, ee, next) {
    // Capture custom headers from our Middleware
    const queryCount = response.headers['x-perf-query-count'];
    const memUsage = response.headers['x-perf-memory-usage'];
    const peakMem = response.headers['x-perf-memory-peak'];

    // Custom metrics for Artillery report
    if (queryCount) {
        ee.emit('customStat', { stat: 'backend_query_count', value: parseInt(queryCount) });
    }

    return next();
}
