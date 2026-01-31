// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'pending_attendance.dart';

// **************************************************************************
// TypeAdapterGenerator
// **************************************************************************

class PendingAttendanceAdapter extends TypeAdapter<PendingAttendance> {
  @override
  final int typeId = 1;

  @override
  PendingAttendance read(BinaryReader reader) {
    final numOfFields = reader.readByte();
    final fields = <int, dynamic>{
      for (int i = 0; i < numOfFields; i++) reader.readByte(): reader.read(),
    };
    return PendingAttendance(
      id: fields[0] as String,
      studentId: fields[1] as int,
      scheduleId: fields[2] as int,
      scannedAt: fields[3] as DateTime,
      syncStatus: fields[4] as SyncStatus,
      retryCount: fields[5] as int,
      lastSyncAttempt: fields[6] as DateTime?,
      lastError: fields[7] as String?,
      serverAttendanceId: fields[8] as int?,
      qrToken: fields[9] as String,
      latitude: fields[10] as double?,
      longitude: fields[11] as double?,
      accuracy: fields[12] as double?,
      deviceId: fields[13] as String,
      requestId: fields[14] as String,
      createdAt: fields[15] as DateTime?,
    );
  }

  @override
  void write(BinaryWriter writer, PendingAttendance obj) {
    writer
      ..writeByte(16)
      ..writeByte(0)
      ..write(obj.id)
      ..writeByte(1)
      ..write(obj.studentId)
      ..writeByte(2)
      ..write(obj.scheduleId)
      ..writeByte(3)
      ..write(obj.scannedAt)
      ..writeByte(4)
      ..write(obj.syncStatus)
      ..writeByte(5)
      ..write(obj.retryCount)
      ..writeByte(6)
      ..write(obj.lastSyncAttempt)
      ..writeByte(7)
      ..write(obj.lastError)
      ..writeByte(8)
      ..write(obj.serverAttendanceId)
      ..writeByte(9)
      ..write(obj.qrToken)
      ..writeByte(10)
      ..write(obj.latitude)
      ..writeByte(11)
      ..write(obj.longitude)
      ..writeByte(12)
      ..write(obj.accuracy)
      ..writeByte(13)
      ..write(obj.deviceId)
      ..writeByte(14)
      ..write(obj.requestId)
      ..writeByte(15)
      ..write(obj.createdAt);
  }

  @override
  int get hashCode => typeId.hashCode;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is PendingAttendanceAdapter &&
          runtimeType == other.runtimeType &&
          typeId == other.typeId;
}

class SyncStatusAdapter extends TypeAdapter<SyncStatus> {
  @override
  final int typeId = 2;

  @override
  SyncStatus read(BinaryReader reader) {
    switch (reader.readByte()) {
      case 0:
        return SyncStatus.pending;
      case 1:
        return SyncStatus.syncing;
      case 2:
        return SyncStatus.sent;
      case 3:
        return SyncStatus.failed;
      default:
        return SyncStatus.pending;
    }
  }

  @override
  void write(BinaryWriter writer, SyncStatus obj) {
    switch (obj) {
      case SyncStatus.pending:
        writer.writeByte(0);
        break;
      case SyncStatus.syncing:
        writer.writeByte(1);
        break;
      case SyncStatus.sent:
        writer.writeByte(2);
        break;
      case SyncStatus.failed:
        writer.writeByte(3);
        break;
    }
  }

  @override
  int get hashCode => typeId.hashCode;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is SyncStatusAdapter &&
          runtimeType == other.runtimeType &&
          typeId == other.typeId;
}
