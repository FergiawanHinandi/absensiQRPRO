import 'package:dio/dio.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:get_it/get_it.dart';

import 'data/models/pending_attendance.dart';
import 'data/repositories/attendance_local_repository.dart';
import 'services/attendance_sync_service.dart';
import 'domain/usecases/submit_attendance_usecase.dart';

/// Dependency injection container
final getIt = GetIt.instance;

/// Initialize all dependencies and services
Future<void> initializeDependencies() async {
  // ========================================
  // HIVE INITIALIZATION
  // ========================================
  await Hive.initFlutter();

  // Register Hive adapters
  if (!Hive.isAdapterRegistered(1)) {
    Hive.registerAdapter(PendingAttendanceAdapter());
  }
  if (!Hive.isAdapterRegistered(2)) {
    Hive.registerAdapter(SyncStatusAdapter());
  }

  // ========================================
  // HTTP CLIENT
  // ========================================
  final dio = Dio(
    BaseOptions(
      baseUrl: 'https://api.absensi.app', // Replace with actual base URL
      connectTimeout: const Duration(seconds: 15),
      receiveTimeout: const Duration(seconds: 15),
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    ),
  );

  // Add auth interceptor
  dio.interceptors.add(AuthInterceptor());

  // Add logging interceptor (debug only)
  dio.interceptors.add(LogInterceptor(requestBody: true, responseBody: true));

  getIt.registerSingleton<Dio>(dio);

  // ========================================
  // REPOSITORIES
  // ========================================
  final localRepo = AttendanceLocalRepository();
  await localRepo.init();
  getIt.registerSingleton<AttendanceLocalRepository>(localRepo);

  // ========================================
  // SERVICES
  // ========================================
  final syncService = AttendanceSyncService(
    localRepository: localRepo,
    dio: dio,
  );
  await syncService.init();
  getIt.registerSingleton<AttendanceSyncService>(syncService);

  // ========================================
  // USE CASES
  // ========================================
  getIt.registerFactory<SubmitAttendanceUseCase>(
    () => SubmitAttendanceUseCase(
      localRepository: getIt<AttendanceLocalRepository>(),
      syncService: getIt<AttendanceSyncService>(),
    ),
  );
}

/// Auth interceptor for adding JWT token
class AuthInterceptor extends Interceptor {
  @override
  void onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    // Get token from secure storage
    final token = await _getAuthToken();
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    if (err.response?.statusCode == 401) {
      // Token expired - trigger re-login
      // EventBus.fire(AuthExpiredEvent());
    }
    handler.next(err);
  }

  Future<String?> _getAuthToken() async {
    // TODO: Implement secure token storage
    // Use flutter_secure_storage in production
    return null;
  }
}

/// Dispose all dependencies
Future<void> disposeDependencies() async {
  getIt<AttendanceSyncService>().dispose();
  await getIt<AttendanceLocalRepository>().close();
  await Hive.close();
}
