import 'package:hive/hive.dart';

part 'pending_attendance.g.dart';

/// Sync status for offline attendance records
enum SyncStatus {
  @HiveField(0)
  pending,

  @HiveField(1)
  syncing,

  @HiveField(2)
  sent,

  @HiveField(3)
  failed,
}

/// Local attendance record for offline-first sync
@HiveType(typeId: 1)
class PendingAttendance extends HiveObject {
  /// Unique local ID (UUID v4)
  @HiveField(0)
  final String id;

  /// Student's user ID
  @HiveField(1)
  final int studentId;

  /// Schedule ID from QR code
  @HiveField(2)
  final int scheduleId;

  /// When the QR was scanned (local time)
  @HiveField(3)
  final DateTime scannedAt;

  /// Current sync status
  @HiveField(4)
  SyncStatus syncStatus;

  /// Number of retry attempts
  @HiveField(5)
  int retryCount;

  /// Last sync attempt timestamp
  @HiveField(6)
  DateTime? lastSyncAttempt;

  /// Error message from last failed sync
  @HiveField(7)
  String? lastError;

  /// Server-assigned attendance ID (after successful sync)
  @HiveField(8)
  int? serverAttendanceId;

  /// QR token data (for signature verification)
  @HiveField(9)
  final String qrToken;

  /// GPS location at scan time
  @HiveField(10)
  final double? latitude;

  @HiveField(11)
  final double? longitude;

  /// GPS accuracy in meters
  @HiveField(12)
  final double? accuracy;

  /// Device ID for anti-joki
  @HiveField(13)
  final String deviceId;

  /// Request ID for idempotency
  @HiveField(14)
  final String requestId;

  /// Created timestamp
  @HiveField(15)
  final DateTime createdAt;

  PendingAttendance({
    required this.id,
    required this.studentId,
    required this.scheduleId,
    required this.scannedAt,
    required this.qrToken,
    required this.deviceId,
    required this.requestId,
    this.syncStatus = SyncStatus.pending,
    this.retryCount = 0,
    this.lastSyncAttempt,
    this.lastError,
    this.serverAttendanceId,
    this.latitude,
    this.longitude,
    this.accuracy,
    DateTime? createdAt,
  }) : createdAt = createdAt ?? DateTime.now();

  /// Check if record should be retried
  bool get shouldRetry {
    if (syncStatus != SyncStatus.failed) return false;
    if (retryCount >= 5) return false; // Max retries

    // Exponential backoff check
    if (lastSyncAttempt != null) {
      final backoffSeconds = _calculateBackoff(retryCount);
      final nextRetry = lastSyncAttempt!.add(Duration(seconds: backoffSeconds));
      return DateTime.now().isAfter(nextRetry);
    }

    return true;
  }

  /// Calculate exponential backoff delay in seconds
  int _calculateBackoff(int attempt) {
    // Base: 5s, then 10s, 20s, 40s, 80s (capped at 80s)
    return (5 * (1 << attempt)).clamp(5, 80);
  }

  /// Mark as syncing
  void markSyncing() {
    syncStatus = SyncStatus.syncing;
    lastSyncAttempt = DateTime.now();
    save();
  }

  /// Mark as successfully sent
  void markSent(int serverId) {
    syncStatus = SyncStatus.sent;
    serverAttendanceId = serverId;
    lastError = null;
    save();
  }

  /// Mark as failed
  void markFailed(String error) {
    syncStatus = SyncStatus.failed;
    retryCount++;
    lastError = error;
    save();
  }

  /// Convert to API request body
  Map<String, dynamic> toApiRequest() {
    return {
      'token': qrToken,
      'request_id': requestId,
      'latitude': latitude,
      'longitude': longitude,
      'accuracy': accuracy,
      'device_info': {
        'device_id': deviceId,
      },
      'scanned_at': scannedAt.toUtc().toIso8601String(),
    };
  }

  @override
  String toString() {
    return 'PendingAttendance(id: $id, scheduleId: $scheduleId, '
        'status: $syncStatus, retries: $retryCount)';
  }
}
