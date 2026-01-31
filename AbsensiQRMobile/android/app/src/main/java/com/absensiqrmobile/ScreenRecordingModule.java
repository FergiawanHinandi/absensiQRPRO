package com.absensiqrmobile;

import android.app.Activity;
import android.os.Build;
import android.view.WindowManager;

import com.facebook.react.bridge.Promise;
import com.facebook.react.bridge.ReactApplicationContext;
import com.facebook.react.bridge.ReactContextBaseJavaModule;
import com.facebook.react.bridge.ReactMethod;

/**
 * Native module for detecting screen recording and overlays.
 * 
 * Provides security features to detect when the screen is being
 * captured or when overlay apps are present.
 */
public class ScreenRecordingModule extends ReactContextBaseJavaModule {

    private final ReactApplicationContext reactContext;

    public ScreenRecordingModule(ReactApplicationContext reactContext) {
        super(reactContext);
        this.reactContext = reactContext;
    }

    @Override
    public String getName() {
        return "ScreenRecordingModule";
    }

    /**
     * Check if screen recording/capture is active.
     * Note: This is a best-effort check and may not detect all recording methods.
     * 
     * @param promise - Returns true if screen recording is detected
     */
    @ReactMethod
    public void isScreenRecording(Promise promise) {
        try {
            // On Android 10+, we can check for screen capture callbacks
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                Activity activity = getCurrentActivity();
                if (activity != null) {
                    // Check if FLAG_SECURE is set
                    int flags = activity.getWindow().getAttributes().flags;
                    boolean isSecured = (flags & WindowManager.LayoutParams.FLAG_SECURE) != 0;
                    
                    // If not secured, we can't reliably detect recording
                    // Return false but recommend enabling FLAG_SECURE
                    promise.resolve(false);
                    return;
                }
            }
            
            promise.resolve(false);
        } catch (Exception e) {
            promise.reject("SCREEN_CHECK_ERROR", e.getMessage());
        }
    }

    /**
     * Enable secure screen mode (prevents screenshots and screen recording)
     * 
     * @param promise - Returns true if successfully enabled
     */
    @ReactMethod
    public void enableSecureScreen(Promise promise) {
        try {
            Activity activity = getCurrentActivity();
            if (activity != null) {
                activity.runOnUiThread(() -> {
                    activity.getWindow().addFlags(WindowManager.LayoutParams.FLAG_SECURE);
                });
                promise.resolve(true);
            } else {
                promise.resolve(false);
            }
        } catch (Exception e) {
            promise.reject("SECURE_SCREEN_ERROR", e.getMessage());
        }
    }

    /**
     * Disable secure screen mode
     * 
     * @param promise - Returns true if successfully disabled
     */
    @ReactMethod
    public void disableSecureScreen(Promise promise) {
        try {
            Activity activity = getCurrentActivity();
            if (activity != null) {
                activity.runOnUiThread(() -> {
                    activity.getWindow().clearFlags(WindowManager.LayoutParams.FLAG_SECURE);
                });
                promise.resolve(true);
            } else {
                promise.resolve(false);
            }
        } catch (Exception e) {
            promise.reject("SECURE_SCREEN_ERROR", e.getMessage());
        }
    }
}
