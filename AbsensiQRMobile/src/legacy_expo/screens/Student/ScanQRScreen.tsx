import React, { useState, useEffect } from "react";
import {
  View,
  Text,
  StyleSheet,
  TouchableOpacity,
  ActivityIndicator,
} from "react-native";
import { CameraView, useCameraPermissions } from "expo-camera";
import * as Location from "expo-location";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import Toast from "react-native-toast-message";

import api from "../../services/api";
import { useNetworkStatus } from "../../hooks/useNetworkStatus";
import { addAttendanceToQueue } from "../../services/offlineService";

interface AttendancePayload {
  token: string;
  latitude: number;
  longitude: number;
}

const submitAttendance = async (payload: AttendancePayload) => {
  const { data } = await api.post("/v1/attendance/scan", payload);
  return data;
};

export const ScanQRScreen = ({ navigation }: any) => {
  const [permission, requestPermission] = useCameraPermissions();
  const [scanned, setScanned] = useState(false);
  const isOnline = useNetworkStatus();

  useEffect(() => {
    requestPermission();
  }, []);

  const queryClient = useQueryClient();
  const mutation = useMutation({
    mutationFn: submitAttendance,
    onSuccess: (data) => {
      Toast.show({
        type: "success",
        text1: "Attendance Recorded!",
        text2: `Successfully clocked in at ${new Date().toLocaleTimeString()}.`,
      });
      queryClient.invalidateQueries({ queryKey: ["attendances"] }); // Invalidate relevant queries
      navigation.goBack();
    },
    onError: (error: any) => {
      const message =
        error.response?.data?.message || "Failed to record attendance.";
      Toast.show({
        type: "error",
        text1: "Submission Failed",
        text2: message,
      });
      setScanned(false); // Allow re-scanning
    },
  });

  const handleBarCodeScanned = async ({ data }: { data: string }) => {
    if (scanned || mutation.isPending) return;
    setScanned(true);

    try {
      const location = await Location.getCurrentPositionAsync({
        accuracy: Location.Accuracy.Low,
      });
      const payload: AttendancePayload = {
        token: data,
        latitude: location.coords.latitude,
        longitude: location.coords.longitude,
      };

      if (isOnline) {
        mutation.mutate(payload);
      } else {
        // Offline: Add to queue
        await addAttendanceToQueue({
          qr_token: payload.token,
          scanned_at: new Date().toISOString(),
          latitude: payload.latitude,
          longitude: payload.longitude,
        });
        navigation.goBack();
      }
    } catch (error) {
      Toast.show({
        type: "error",
        text1: "Error",
        text2: "Could not get location. Please enable location services.",
      });
      setScanned(false);
    }
  };

  if (!permission)
    return (
      <View style={styles.container}>
        <ActivityIndicator />
      </View>
    );

  if (!permission.granted) {
    return (
      <View style={styles.container}>
        <Text style={styles.message}>
          Camera permission is required to scan QR codes.
        </Text>
        <TouchableOpacity style={styles.button} onPress={requestPermission}>
          <Text style={styles.buttonText}>Grant Camera Permission</Text>
        </TouchableOpacity>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <CameraView
        style={styles.camera}
        onBarcodeScanned={scanned ? undefined : handleBarCodeScanned}
        barcodeScannerSettings={{ barcodeTypes: ["qr"] }}
      >
        <View style={styles.overlay}>
          <View style={styles.scanArea}>
            <Text style={styles.scanText}>
              {mutation.isPending ? "Processing..." : "Aim at QR Code"}
            </Text>
          </View>
        </View>
      </CameraView>
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: "#000",
    justifyContent: "center",
  },
  camera: {
    flex: 1,
  },
  overlay: {
    flex: 1,
    backgroundColor: "rgba(0,0,0,0.5)",
    justifyContent: "center",
    alignItems: "center",
  },
  scanArea: {
    width: 250,
    height: 250,
    borderWidth: 2,
    borderColor: "#fff",
    borderRadius: 12,
    justifyContent: "center",
    alignItems: "center",
  },
  scanText: {
    color: "#fff",
    fontSize: 16,
    fontWeight: "600",
    textAlign: "center",
  },
  message: {
    textAlign: "center",
    paddingBottom: 10,
    color: "#fff",
    fontSize: 16,
  },
  button: {
    backgroundColor: "#2563eb",
    padding: 16,
    borderRadius: 8,
    marginHorizontal: 20,
  },
  buttonText: {
    color: "#fff",
    textAlign: "center",
    fontSize: 16,
    fontWeight: "600",
  },
});
