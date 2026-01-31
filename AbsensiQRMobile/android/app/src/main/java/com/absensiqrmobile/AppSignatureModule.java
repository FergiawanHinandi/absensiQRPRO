package com.absensiqrmobile;

import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.content.pm.Signature;
import android.os.Build;
import android.util.Base64;

import com.facebook.react.bridge.Promise;
import com.facebook.react.bridge.ReactApplicationContext;
import com.facebook.react.bridge.ReactContextBaseJavaModule;
import com.facebook.react.bridge.ReactMethod;

import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;

/**
 * Native module for Android app signature verification.
 * 
 * Used to detect if the app has been tampered with by comparing
 * the current signing certificate hash against the known production hash.
 */
public class AppSignatureModule extends ReactContextBaseJavaModule {

    private final ReactApplicationContext reactContext;

    public AppSignatureModule(ReactApplicationContext reactContext) {
        super(reactContext);
        this.reactContext = reactContext;
    }

    @Override
    public String getName() {
        return "AppSignatureModule";
    }

    /**
     * Get the SHA-256 hash of the app's signing certificate.
     * 
     * @param promise - Returns the Base64-encoded SHA-256 hash
     */
    @ReactMethod
    public void getSignatureHash(Promise promise) {
        try {
            String packageName = reactContext.getPackageName();
            PackageManager pm = reactContext.getPackageManager();
            
            PackageInfo packageInfo;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                packageInfo = pm.getPackageInfo(packageName, PackageManager.GET_SIGNING_CERTIFICATES);
                Signature[] signatures = packageInfo.signingInfo.getApkContentsSigners();
                if (signatures != null && signatures.length > 0) {
                    String hash = getSignatureHash(signatures[0]);
                    promise.resolve(hash);
                    return;
                }
            } else {
                packageInfo = pm.getPackageInfo(packageName, PackageManager.GET_SIGNATURES);
                Signature[] signatures = packageInfo.signatures;
                if (signatures != null && signatures.length > 0) {
                    String hash = getSignatureHash(signatures[0]);
                    promise.resolve(hash);
                    return;
                }
            }
            
            promise.reject("NO_SIGNATURE", "Could not retrieve app signature");
        } catch (PackageManager.NameNotFoundException e) {
            promise.reject("PACKAGE_NOT_FOUND", "Package not found: " + e.getMessage());
        } catch (Exception e) {
            promise.reject("SIGNATURE_ERROR", "Error getting signature: " + e.getMessage());
        }
    }

    /**
     * Calculate SHA-256 hash of the signature
     */
    private String getSignatureHash(Signature signature) throws NoSuchAlgorithmException {
        MessageDigest md = MessageDigest.getInstance("SHA-256");
        byte[] digest = md.digest(signature.toByteArray());
        return Base64.encodeToString(digest, Base64.NO_WRAP);
    }

    /**
     * Check if the app is running in debug mode
     * 
     * @param promise - Returns true if debug mode, false otherwise
     */
    @ReactMethod
    public void isDebugBuild(Promise promise) {
        try {
            boolean isDebug = (reactContext.getApplicationInfo().flags & 
                android.content.pm.ApplicationInfo.FLAG_DEBUGGABLE) != 0;
            promise.resolve(isDebug);
        } catch (Exception e) {
            promise.reject("DEBUG_CHECK_ERROR", e.getMessage());
        }
    }

    /**
     * Get the app's installation source
     * 
     * @param promise - Returns the installer package name (e.g., "com.android.vending" for Play Store)
     */
    @ReactMethod
    public void getInstallerPackage(Promise promise) {
        try {
            String packageName = reactContext.getPackageName();
            PackageManager pm = reactContext.getPackageManager();
            
            String installer;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                installer = pm.getInstallSourceInfo(packageName).getInstallingPackageName();
            } else {
                installer = pm.getInstallerPackageName(packageName);
            }
            
            promise.resolve(installer != null ? installer : "unknown");
        } catch (Exception e) {
            promise.reject("INSTALLER_ERROR", e.getMessage());
        }
    }
}
