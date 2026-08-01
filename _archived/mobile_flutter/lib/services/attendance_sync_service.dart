import 'dart:async';
import 'dart:io';
import 'package:dio/dio.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import '../models/pending_attendance.dart';
import '../repositories/attendance_local_repository.dart';

/// Service for syncing offline attendance records to server
class AttendanceSyncService {
  final AttendanceLocalRepository _localRepo;
  final Dio _dio;
  final Connectivity _connectivity;

  Timer? _syncTimer;
  bool _isSyncing = false;
  bool _isOnline = true;

  /// Stream controller for sync status updates
  final _syncStatusController = StreamController<SyncStatusUpdate>.broadcast();
  Stream<SyncStatusUpdate> get syncStatusStream => _syncStatusController.stream;

  /// Sync interval in seconds
  static const int syncIntervalSeconds = 30;

  /// Max concurrent sync requests
  static const int maxConcurrentSyncs = 3;

  /// API endpoint for attendance submission
  static const String attendanceEndpoint = '/api/v1/attendance/scan';

  AttendanceSyncService({
    required AttendanceLocalRepository localRepository,
    required Dio dio,
    Connectivity? connectivity,
  }) : _localRepo = localRepository,
       _dio = dio,
       _connectivity = connectivity ?? Connectivity();

  /// Initialize the sync service
  Future<void> init() async {
    await _localRepo.init();

    // Reset any stuck syncing records (from previous app crash)
    final resetCount = await _localRepo.resetStuckRecords();
    if (resetCount > 0) {
      debugPrint('AttendanceSyncService: Reset $resetCount stuck records');
    }

    // Listen to connectivity changes
    _connectivity.onConnectivityChanged.listen(_onConnectivityChanged);

    // Check initial connectivity
    final result = await _connectivity.checkConnectivity();
    _isOnline = result != ConnectivityResult.none;

    // Start periodic sync
    startPeriodicSync();

    debugPrint('AttendanceSyncService initialized. Online: $_isOnline');
  }

  /// Handle connectivity changes
  void _onConnectivityChanged(ConnectivityResult result) {
    final wasOnline = _isOnline;
    _isOnline = result != ConnectivityResult.none;

    debugPrint('Connectivity changed: $result (online: $_isOnline)');

    // If just came online, trigger immediate sync
    if (!wasOnline && _isOnline) {
      debugPrint('Back online! Triggering immediate sync...');
      syncNow();
    }

    _syncStatusController.add(
      SyncStatusUpdate(
        type: SyncStatusType.connectivityChanged,
        isOnline: _isOnline,
      ),
    );
  }

  /// Start periodic sync timer
  void startPeriodicSync() {
    _syncTimer?.cancel();
    _syncTimer = Timer.periodic(
      const Duration(seconds: syncIntervalSeconds),
      (_) => syncNow(),
    );
    debugPrint('Periodic sync started: every $syncIntervalSeconds seconds');
  }

  /// Stop periodic sync timer
  void stopPeriodicSync() {
    _syncTimer?.cancel();
    _syncTimer = null;
    debugPrint('Periodic sync stopped');
  }

  /// Trigger immediate sync
  Future<SyncResult> syncNow() async {
    if (_isSyncing) {
      debugPrint('Sync already in progress, skipping...');
      return SyncResult(success: 0, failed: 0, skipped: true);
    }

    if (!_isOnline) {
      debugPrint('Device is offline, skipping sync...');
      return SyncResult(
        success: 0,
        failed: 0,
        skipped: true,
        reason: 'offline',
      );
    }

    _isSyncing = true;
    _syncStatusController.add(
      SyncStatusUpdate(type: SyncStatusType.syncStarted),
    );

    int successCount = 0;
    int failedCount = 0;

    try {
      final pendingRecords = _localRepo.getPendingRecords();

      if (pendingRecords.isEmpty) {
        debugPrint('No pending records to sync');
        return SyncResult(success: 0, failed: 0, skipped: false);
      }

      debugPrint('Syncing ${pendingRecords.length} pending records...');

      // Process in batches to limit concurrent requests
      for (var i = 0; i < pendingRecords.length; i += maxConcurrentSyncs) {
        final batch = pendingRecords.skip(i).take(maxConcurrentSyncs).toList();
        final results = await Future.wait(
          batch.map((record) => _syncRecord(record)),
        );

        for (final result in results) {
          if (result) {
            successCount++;
          } else {
            failedCount++;
          }
        }
      }

      debugPrint('Sync complete: $successCount success, $failedCount failed');
    } catch (e) {
      debugPrint('Sync error: $e');
    } finally {
      _isSyncing = false;
      _syncStatusController.add(
        SyncStatusUpdate(
          type: SyncStatusType.syncCompleted,
          successCount: successCount,
          failedCount: failedCount,
        ),
      );
    }

    return SyncResult(
      success: successCount,
      failed: failedCount,
      skipped: false,
    );
  }

  /// Sync a single record to server
  Future<bool> _syncRecord(PendingAttendance record) async {
    try {
      // Mark as syncing
      record.markSyncing();

      _syncStatusController.add(
        SyncStatusUpdate(
          type: SyncStatusType.recordSyncing,
          recordId: record.id,
        ),
      );

      // Make API request
      final response = await _dio.post(
        attendanceEndpoint,
        data: record.toApiRequest(),
        options: Options(
          headers: {
            'X-Request-ID': record.requestId,
            'X-Idempotency-Key': record.requestId,
          },
          // Short timeout for attendance
          sendTimeout: const Duration(seconds: 10),
          receiveTimeout: const Duration(seconds: 10),
        ),
      );

      // Only mark as sent on HTTP 200/201
      if (response.statusCode == 200 || response.statusCode == 201) {
        final data = response.data;
        final serverId = data['data']?['id'] ?? data['id'] ?? 0;

        record.markSent(serverId);

        _syncStatusController.add(
          SyncStatusUpdate(
            type: SyncStatusType.recordSynced,
            recordId: record.id,
            serverId: serverId,
          ),
        );

        debugPrint(
          'Record ${record.id} synced successfully (server ID: $serverId)',
        );
        return true;
      } else {
        // Non-200 response
        record.markFailed('HTTP ${response.statusCode}');
        debugPrint('Record ${record.id} failed: HTTP ${response.statusCode}');
        return false;
      }
    } on DioException catch (e) {
      String errorMessage;

      if (e.type == DioExceptionType.connectionTimeout ||
          e.type == DioExceptionType.sendTimeout ||
          e.type == DioExceptionType.receiveTimeout) {
        errorMessage = 'Timeout';
      } else if (e.type == DioExceptionType.connectionError) {
        errorMessage = 'Connection error';
      } else if (e.response != null) {
        // Server returned error response
        final statusCode = e.response!.statusCode;

        // Handle specific status codes
        if (statusCode == 409) {
          // Conflict - already recorded (treat as success for idempotency)
          final data = e.response!.data;
          final serverId = data['data']?['id'] ?? 0;
          record.markSent(serverId);
          debugPrint(
            'Record ${record.id} already exists on server (409 Conflict)',
          );
          return true;
        } else if (statusCode == 400 || statusCode == 422) {
          // Validation error - don't retry
          errorMessage =
              'Validation error: ${e.response!.data['message'] ?? 'Unknown'}';
          record.retryCount = 999; // Prevent further retries
        } else {
          errorMessage = 'HTTP $statusCode';
        }
      } else {
        errorMessage = e.message ?? 'Unknown error';
      }

      record.markFailed(errorMessage);

      _syncStatusController.add(
        SyncStatusUpdate(
          type: SyncStatusType.recordFailed,
          recordId: record.id,
          error: errorMessage,
        ),
      );

      debugPrint(
        'Record ${record.id} failed: $errorMessage (retry: ${record.retryCount})',
      );
      return false;
    } catch (e) {
      record.markFailed(e.toString());
      debugPrint('Record ${record.id} error: $e');
      return false;
    }
  }

  /// Get current sync statistics
  Map<String, int> getStats() {
    return _localRepo.getStats();
  }

  /// Cleanup old sent records
  Future<int> cleanup() async {
    return await _localRepo.cleanupSentRecords();
  }

  /// Dispose the service
  void dispose() {
    _syncTimer?.cancel();
    _syncStatusController.close();
  }
}

/// Types of sync status updates
enum SyncStatusType {
  connectivityChanged,
  syncStarted,
  syncCompleted,
  recordSyncing,
  recordSynced,
  recordFailed,
}

/// Sync status update event
class SyncStatusUpdate {
  final SyncStatusType type;
  final String? recordId;
  final int? serverId;
  final String? error;
  final bool? isOnline;
  final int? successCount;
  final int? failedCount;

  SyncStatusUpdate({
    required this.type,
    this.recordId,
    this.serverId,
    this.error,
    this.isOnline,
    this.successCount,
    this.failedCount,
  });

  @override
  String toString() => 'SyncStatusUpdate(type: $type, recordId: $recordId)';
}

/// Result of a sync operation
class SyncResult {
  final int success;
  final int failed;
  final bool skipped;
  final String? reason;

  SyncResult({
    required this.success,
    required this.failed,
    required this.skipped,
    this.reason,
  });

  int get total => success + failed;
  bool get hasFailures => failed > 0;

  @override
  String toString() =>
      'SyncResult(success: $success, failed: $failed, skipped: $skipped)';
}
