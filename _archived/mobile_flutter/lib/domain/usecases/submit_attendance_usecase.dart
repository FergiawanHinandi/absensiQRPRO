import 'dart:convert';
import 'package:flutter/foundation.dart';
import '../data/models/pending_attendance.dart';
import '../data/repositories/attendance_local_repository.dart';
import '../services/attendance_sync_service.dart';

/// Result of QR scan submission
class ScanSubmissionResult {
  final bool success;
  final String message;
  final PendingAttendance? attendance;
  final bool isDuplicate;
  final bool isOffline;

  ScanSubmissionResult({
    required this.success,
    required this.message,
    this.attendance,
    this.isDuplicate = false,
    this.isOffline = false,
  });

  factory ScanSubmissionResult.success(
    PendingAttendance attendance, {
    bool isOffline = false,
  }) {
    return ScanSubmissionResult(
      success: true,
      message: isOffline
          ? 'Absensi tersimpan. Akan dikirim saat online.'
          : 'Absensi berhasil dicatat.',
      attendance: attendance,
      isOffline: isOffline,
    );
  }

  factory ScanSubmissionResult.duplicate(PendingAttendance existing) {
    return ScanSubmissionResult(
      success: false,
      message: 'Anda sudah absen untuk jadwal ini hari ini.',
      attendance: existing,
      isDuplicate: true,
    );
  }

  factory ScanSubmissionResult.error(String message) {
    return ScanSubmissionResult(success: false, message: message);
  }
}

/// Parsed QR code data
class QrCodeData {
  final int scheduleId;
  final String token;
  final int? expiresAt;
  final String? nonce;
  final String? signature;

  QrCodeData({
    required this.scheduleId,
    required this.token,
    this.expiresAt,
    this.nonce,
    this.signature,
  });

  /// Parse QR code content
  /// Supports both JSON format and compact format
  factory QrCodeData.parse(String rawData) {
    try {
      // Try JSON format first
      if (rawData.startsWith('{')) {
        final json = jsonDecode(rawData) as Map<String, dynamic>;
        return QrCodeData(
          scheduleId: json['id'] ?? json['schedule_id'],
          token: rawData,
          expiresAt: json['exp'],
          nonce: json['n'],
          signature: json['sig'],
        );
      }

      // Try base64 encoded format
      if (rawData.length > 50 && !rawData.contains(':')) {
        final decoded = utf8.decode(base64Decode(rawData));
        return QrCodeData.parse(decoded);
      }

      // Fallback: compact format (schedule_id:nonce:signature)
      final parts = rawData.split(':');
      if (parts.length >= 2) {
        return QrCodeData(
          scheduleId: int.parse(parts[0]),
          token: rawData,
          nonce: parts.length > 1 ? parts[1] : null,
          signature: parts.length > 2 ? parts[2] : null,
        );
      }

      throw FormatException('Invalid QR format');
    } catch (e) {
      throw FormatException('Cannot parse QR code: $e');
    }
  }

  /// Check if QR code has expired
  bool get isExpired {
    if (expiresAt == null) return false;
    final expiry = DateTime.fromMillisecondsSinceEpoch(expiresAt! * 1000);
    return DateTime.now().isAfter(expiry);
  }
}

/// Use case for handling QR attendance submission
class SubmitAttendanceUseCase {
  final AttendanceLocalRepository _localRepo;
  final AttendanceSyncService _syncService;

  SubmitAttendanceUseCase({
    required AttendanceLocalRepository localRepository,
    required AttendanceSyncService syncService,
  }) : _localRepo = localRepository,
       _syncService = syncService;

  /// Submit attendance from scanned QR code
  ///
  /// This method implements offline-first strategy:
  /// 1. Parse and validate QR code
  /// 2. Check for duplicates
  /// 3. Store locally
  /// 4. Trigger sync (if online)
  Future<ScanSubmissionResult> execute({
    required int studentId,
    required String qrData,
    required String deviceId,
    double? latitude,
    double? longitude,
    double? accuracy,
  }) async {
    try {
      // Step 1: Parse QR code
      final qrCode = QrCodeData.parse(qrData);

      // Step 2: Validate QR code
      if (qrCode.isExpired) {
        return ScanSubmissionResult.error(
          'Kode QR sudah kadaluarsa. Minta guru untuk generate ulang.',
        );
      }

      // Step 3: Check for duplicate
      if (_localRepo.hasAttendanceForToday(studentId, qrCode.scheduleId)) {
        final existing = _localRepo.findDuplicate(
          studentId,
          qrCode.scheduleId,
          DateTime.now(),
        );
        if (existing != null && existing.syncStatus != SyncStatus.failed) {
          return ScanSubmissionResult.duplicate(existing);
        }
      }

      // Step 4: Store locally
      final attendance = await _localRepo.storeScan(
        studentId: studentId,
        scheduleId: qrCode.scheduleId,
        qrToken: qrCode.token,
        deviceId: deviceId,
        latitude: latitude,
        longitude: longitude,
        accuracy: accuracy,
      );

      debugPrint('Attendance stored locally: ${attendance.id}');

      // Step 5: Trigger immediate sync
      final syncResult = await _syncService.syncNow();

      // Determine if stored offline or synced
      final isOffline = syncResult.skipped || syncResult.failed > 0;

      return ScanSubmissionResult.success(attendance, isOffline: isOffline);
    } on FormatException catch (e) {
      return ScanSubmissionResult.error('Format QR tidak valid: ${e.message}');
    } catch (e) {
      debugPrint('Submit attendance error: $e');
      return ScanSubmissionResult.error('Terjadi kesalahan: ${e.toString()}');
    }
  }

  /// Get attendance history for student
  Future<List<PendingAttendance>> getHistory() async {
    final all = [
      ..._localRepo.getSentRecords(),
      ..._localRepo.getPendingRecords(),
      ..._localRepo.getFailedRecords(),
    ];

    // Sort by scan time descending
    all.sort((a, b) => b.scannedAt.compareTo(a.scannedAt));
    return all;
  }

  /// Get sync statistics
  Map<String, int> getStats() {
    return _localRepo.getStats();
  }

  /// Retry failed records
  Future<int> retryFailed() async {
    final failed = _localRepo.getFailedRecords();
    int retriedCount = 0;

    for (final record in failed) {
      if (record.retryCount < 5) {
        record.syncStatus = SyncStatus.pending;
        record.retryCount = 0;
        await record.save();
        retriedCount++;
      }
    }

    if (retriedCount > 0) {
      _syncService.syncNow(); // Trigger sync
    }

    return retriedCount;
  }
}
