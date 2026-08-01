/**
 * Mobile Security Event API
 *
 * Handles reporting of security violations to the backend.
 * All security events are logged for audit and monitoring.
 */

import {secureApi} from './secureClient';
import {storage} from '../utils/storage';
import {Platform} from 'react-native';
import {
  SecurityViolation,
  SecurityViolationType,
} from '../services/DeviceSecurityService';

export interface MobileSecurityEvent {
  event_type: SecurityViolationType | string;
  severity: 'low' | 'medium' | 'high' | 'critical';
  device_info: {
    platform: string;
    os_version: string;
    model: string;
    manufacturer: string;
    device_fingerprint: string;
  };
  details?: Record<string, unknown>;
  timestamp: string;
}

export interface SecurityEventResponse {
  success: boolean;
  message: string;
  event_id?: string;
}

/**
 * Mobile Security API client
 */
class MobileSecurityApi {
  private eventQueue: MobileSecurityEvent[] = [];
  private isProcessingQueue = false;
  private readonly MAX_QUEUE_SIZE = 50;
  private readonly BATCH_SIZE = 10;

  /**
   * Report a security violation to the backend
   */
  async reportViolation(
    violation: SecurityViolation,
    deviceFingerprint: string,
    additionalDetails?: Record<string, unknown>,
  ): Promise<SecurityEventResponse> {
    const event = this.createSecurityEvent(
      violation.type,
      violation.severity,
      deviceFingerprint,
      {...violation.details, ...additionalDetails},
    );

    return this.sendEvent(event);
  }

  /**
   * Report multiple violations at once
   */
  async reportViolations(
    violations: SecurityViolation[],
    deviceFingerprint: string,
  ): Promise<void> {
    const events = violations.map(violation =>
      this.createSecurityEvent(
        violation.type,
        violation.severity,
        deviceFingerprint,
        violation.details,
      ),
    );

    // Queue events for batch processing
    this.queueEvents(events);
    await this.processQueue();
  }

  /**
   * Report a generic security event
   */
  async reportEvent(
    eventType: string,
    severity: 'low' | 'medium' | 'high' | 'critical',
    deviceFingerprint: string,
    details?: Record<string, unknown>,
  ): Promise<SecurityEventResponse> {
    const event = this.createSecurityEvent(
      eventType,
      severity,
      deviceFingerprint,
      details,
    );
    return this.sendEvent(event);
  }

  /**
   * Report SSL pinning failure
   */
  async reportSSLPinningFailure(
    deviceFingerprint: string,
    errorDetails: {
      url?: string;
      errorMessage?: string;
    },
  ): Promise<SecurityEventResponse> {
    return this.reportEvent(
      'ssl_pinning_failure',
      'critical',
      deviceFingerprint,
      {
        ...errorDetails,
        connection_intercepted: true,
      },
    );
  }

  /**
   * Report device integrity check on login
   */
  async reportDeviceIntegrityCheck(
    deviceFingerprint: string,
    checkResult: {
      isSecure: boolean;
      riskLevel: string;
      violationCount: number;
    },
  ): Promise<SecurityEventResponse> {
    return this.reportEvent(
      'device_integrity_check',
      checkResult.isSecure ? 'low' : 'high',
      deviceFingerprint,
      checkResult,
    );
  }

  /**
   * Create a security event object
   */
  private createSecurityEvent(
    eventType: string,
    severity: 'low' | 'medium' | 'high' | 'critical',
    deviceFingerprint: string,
    details?: Record<string, unknown>,
  ): MobileSecurityEvent {
    const constants = Platform.constants as any;

    return {
      event_type: eventType,
      severity,
      device_info: {
        platform: Platform.OS,
        os_version: Platform.Version?.toString() || 'unknown',
        model: constants?.Model || 'unknown',
        manufacturer: constants?.Manufacturer || constants?.Brand || 'unknown',
        device_fingerprint: deviceFingerprint,
      },
      details,
      timestamp: new Date().toISOString(),
    };
  }

  /**
   * Send a single security event to the backend
   */
  private async sendEvent(
    event: MobileSecurityEvent,
  ): Promise<SecurityEventResponse> {
    try {
      // Check if user is authenticated
      const token = await storage.getToken();

      if (!token) {
        // Queue event for later if not authenticated
        this.queueEvents([event]);
        return {
          success: true,
          message: 'Event queued for later submission',
        };
      }

      const response = await secureApi.post<SecurityEventResponse>(
        '/v1/security/mobile-event',
        event,
      );

      return response.data;
    } catch (error) {
      console.error('Failed to report security event:', error);

      // Queue for retry
      this.queueEvents([event]);

      return {
        success: false,
        message: 'Failed to report event, queued for retry',
      };
    }
  }

  /**
   * Queue events for batch processing
   */
  private queueEvents(events: MobileSecurityEvent[]): void {
    this.eventQueue.push(...events);

    // Trim queue if too large
    if (this.eventQueue.length > this.MAX_QUEUE_SIZE) {
      this.eventQueue = this.eventQueue.slice(-this.MAX_QUEUE_SIZE);
    }
  }

  /**
   * Process queued events
   */
  async processQueue(): Promise<void> {
    if (this.isProcessingQueue || this.eventQueue.length === 0) {
      return;
    }

    this.isProcessingQueue = true;

    try {
      const token = await storage.getToken();
      if (!token) {
        return; // Can't process without auth
      }

      // Process in batches
      while (this.eventQueue.length > 0) {
        const batch = this.eventQueue.splice(0, this.BATCH_SIZE);

        try {
          await secureApi.post('/v1/security/mobile-events/batch', {
            events: batch,
          });
        } catch (error) {
          // Re-queue failed batch
          this.eventQueue.unshift(...batch);
          break;
        }
      }
    } finally {
      this.isProcessingQueue = false;
    }
  }

  /**
   * Get pending event count
   */
  getPendingEventCount(): number {
    return this.eventQueue.length;
  }

  /**
   * Clear event queue
   */
  clearQueue(): void {
    this.eventQueue = [];
  }
}

// Export singleton
export const mobileSecurityApi = new MobileSecurityApi();
export default mobileSecurityApi;
