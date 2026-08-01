import 'package:hive_flutter/hive_flutter.dart';
import 'package:uuid/uuid.dart';
import '../models/pending_attendance.dart';

/// Repository for managing local attendance storage
class AttendanceLocalRepository {
  static const String _boxName = 'pending_attendances';
  static const _uuid = Uuid();

  Box<PendingAttendance>? _box;

  /// Initialize the repository
  Future<void> init() async {
    if (_box != null && _box!.isOpen) return;

    // Register adapters
    if (!Hive.isAdapterRegistered(1)) {
      Hive.registerAdapter(PendingAttendanceAdapter());
    }
    if (!Hive.isAdapterRegistered(2)) {
      Hive.registerAdapter(SyncStatusAdapter());
    }

    _box = await Hive.openBox<PendingAttendance>(_boxName);
  }

  Box<PendingAttendance> get _safeBox {
    if (_box == null || !_box!.isOpen) {
      throw StateError(
        'AttendanceLocalRepository not initialized. Call init() first.',
      );
    }
    return _box!;
  }

  /// Store new attendance scan locally
  ///
  /// Returns the created [PendingAttendance] record
  Future<PendingAttendance> storeScan({
    required int studentId,
    required int scheduleId,
    required String qrToken,
    required String deviceId,
    double? latitude,
    double? longitude,
    double? accuracy,
  }) async {
    final now = DateTime.now();
    final requestId = _uuid.v4();
    final id = _uuid.v4();

    // Check for duplicate (same schedule, same day)
    final existing = findDuplicate(studentId, scheduleId, now);
    if (existing != null) {
      // Return existing if already pending/syncing/sent
      if (existing.syncStatus != SyncStatus.failed) {
        return existing;
      }
      // If failed, we can retry with same record
      existing.syncStatus = SyncStatus.pending;
      existing.retryCount = 0;
      await existing.save();
      return existing;
    }

    final attendance = PendingAttendance(
      id: id,
      studentId: studentId,
      scheduleId: scheduleId,
      scannedAt: now,
      qrToken: qrToken,
      deviceId: deviceId,
      requestId: requestId,
      latitude: latitude,
      longitude: longitude,
      accuracy: accuracy,
      syncStatus: SyncStatus.pending,
    );

    await _safeBox.put(id, attendance);
    return attendance;
  }

  /// Find duplicate attendance for same schedule on same day
  PendingAttendance? findDuplicate(
    int studentId,
    int scheduleId,
    DateTime date,
  ) {
    final startOfDay = DateTime(date.year, date.month, date.day);
    final endOfDay = startOfDay.add(const Duration(days: 1));

    try {
      return _safeBox.values.firstWhere(
        (a) =>
            a.studentId == studentId &&
            a.scheduleId == scheduleId &&
            a.scannedAt.isAfter(startOfDay) &&
            a.scannedAt.isBefore(endOfDay),
      );
    } catch (_) {
      return null;
    }
  }

  /// Check if attendance already exists for today
  bool hasAttendanceForToday(int studentId, int scheduleId) {
    return findDuplicate(studentId, scheduleId, DateTime.now()) != null;
  }

  /// Get all pending records (pending or failed with retry allowed)
  List<PendingAttendance> getPendingRecords() {
    return _safeBox.values
        .where((a) => a.syncStatus == SyncStatus.pending || a.shouldRetry)
        .toList()
      ..sort((a, b) => a.createdAt.compareTo(b.createdAt)); // FIFO order
  }

  /// Get records currently being synced
  List<PendingAttendance> getSyncingRecords() {
    return _safeBox.values
        .where((a) => a.syncStatus == SyncStatus.syncing)
        .toList();
  }

  /// Get all failed records
  List<PendingAttendance> getFailedRecords() {
    return _safeBox.values
        .where((a) => a.syncStatus == SyncStatus.failed)
        .toList();
  }

  /// Get successfully synced records
  List<PendingAttendance> getSentRecords() {
    return _safeBox.values
        .where((a) => a.syncStatus == SyncStatus.sent)
        .toList();
  }

  /// Get record by ID
  PendingAttendance? getById(String id) {
    return _safeBox.get(id);
  }

  /// Delete a record
  Future<void> delete(String id) async {
    await _safeBox.delete(id);
  }

  /// Delete all sent records older than specified duration
  Future<int> cleanupSentRecords({
    Duration olderThan = const Duration(days: 7),
  }) async {
    final cutoff = DateTime.now().subtract(olderThan);
    final toDelete = _safeBox.values
        .where(
          (a) =>
              a.syncStatus == SyncStatus.sent && a.createdAt.isBefore(cutoff),
        )
        .map((a) => a.id)
        .toList();

    for (final id in toDelete) {
      await _safeBox.delete(id);
    }

    return toDelete.length;
  }

  /// Get sync statistics
  Map<String, int> getStats() {
    final values = _safeBox.values.toList();
    return {
      'total': values.length,
      'pending': values.where((a) => a.syncStatus == SyncStatus.pending).length,
      'syncing': values.where((a) => a.syncStatus == SyncStatus.syncing).length,
      'sent': values.where((a) => a.syncStatus == SyncStatus.sent).length,
      'failed': values.where((a) => a.syncStatus == SyncStatus.failed).length,
    };
  }

  /// Reset stuck syncing records (e.g., after app crash)
  Future<int> resetStuckRecords() async {
    int count = 0;
    for (final attendance in _safeBox.values) {
      if (attendance.syncStatus == SyncStatus.syncing) {
        // If syncing for more than 2 minutes, reset to pending
        if (attendance.lastSyncAttempt != null &&
            DateTime.now().difference(attendance.lastSyncAttempt!).inMinutes >
                2) {
          attendance.syncStatus = SyncStatus.pending;
          await attendance.save();
          count++;
        }
      }
    }
    return count;
  }

  /// Close the box
  Future<void> close() async {
    await _box?.close();
    _box = null;
  }
}
