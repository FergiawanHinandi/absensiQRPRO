import React, { useState, useEffect } from "react";
import {
  StyleSheet,
  Alert,
  KeyboardAvoidingView,
  Platform,
  TouchableOpacity,
  View,
} from "react-native";
import {
  Button,
  Card,
  TextInput,
  Text,
  useTheme,
  Icon,
  Divider,
} from "react-native-paper";
import { LinearGradient } from "expo-linear-gradient";
import * as Animatable from "react-native-animatable";
import * as LocalAuthentication from "expo-local-authentication";
import * as Device from "expo-device";
import * as SecureStore from "expo-secure-store";

import { useAuth } from "../../hooks/useAuth";
import { useGoogleAuth } from "../../hooks/useGoogleAuth";
import { useAuthStore } from "../../store/useAuthStore";

const BIOMETRIC_CREDENTIALS_KEY = "biometric_credentials";

export const LoginScreen = () => {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [isPasswordVisible, setIsPasswordVisible] = useState(false);

  const { login, isLoading: isEmailLoading } = useAuth();
  const { promptGoogleLogin, isLoading: isGoogleLoading } = useGoogleAuth();
  const { isBiometricEnabled, enableBiometrics } = useAuthStore();

  const theme = useTheme();
  const isLoading = isEmailLoading || isGoogleLoading;

  // ... (biometric handlers remain the same)
  const handleBiometricLogin = async () => {
    try {
      const isSupported =
        (await LocalAuthentication.hasHardwareAsync()) &&
        (await LocalAuthentication.isEnrolledAsync());
      if (!isSupported) return;
      const result = await LocalAuthentication.authenticateAsync({
        promptMessage: "Login to AbsensiQR Pro",
      });
      if (result.success) {
        const credsJson = await SecureStore.getItemAsync(
          BIOMETRIC_CREDENTIALS_KEY,
        );
        if (credsJson) {
          const { email: savedEmail, password: savedPassword } =
            JSON.parse(credsJson);
          const deviceName = Device.osName ?? "unknown";
          login({
            email: savedEmail,
            password: savedPassword,
            device_name: deviceName,
          });
        }
      }
    } catch (error) {
      console.error("Biometric auth error:", error);
    }
  };

  useEffect(() => {
    if (isBiometricEnabled) handleBiometricLogin();
  }, [isBiometricEnabled]);

  const promptToEnableBiometrics = () => {
    Alert.alert(
      "Enable Biometric Login?",
      "Use fingerprint/face to log in next time?",
      [
        { text: "No, thanks", style: "cancel" },
        {
          text: "Yes, Enable",
          onPress: () => enableBiometrics({ email, password }),
        },
      ],
    );
  };

  const handleLogin = () => {
    if (!email || !password) {
      Alert.alert("Error", "Email dan password harus diisi");
      return;
    }
    const deviceName = Device.osName ?? "unknown";
    login(
      { email, password, device_name: deviceName },
      {
        onSuccess: () => {
          if (!isBiometricEnabled) promptToEnableBiometrics();
        },
      },
    );
  };

  return (
    <LinearGradient colors={["#7dd3fc", "#0c4a6e"]} style={styles.container}>
      <KeyboardAvoidingView
        behavior={Platform.OS === "ios" ? "padding" : "height"}
        style={styles.keyboardView}
      >
        <Animatable.View animation="fadeInUp" duration={500}>
          <Card style={styles.card}>
            <Card.Content>
              <Animatable.View animation="fadeInUp" delay={200}>
                <Text
                  variant="headlineLarge"
                  style={[styles.title, { color: theme.colors.primary }]}
                >
                  AbsensiQR Pro
                </Text>
              </Animatable.View>
              <Animatable.View animation="fadeInUp" delay={400}>
                <Text variant="bodyMedium" style={styles.subtitle}>
                  Sistem Absensi Sekolah
                </Text>
              </Animatable.View>

              <Animatable.View animation="fadeInUp" delay={500}>
                <TextInput
                  label="Email"
                  value={email}
                  onChangeText={setEmail}
                  style={styles.input}
                  autoCapitalize="none"
                  keyboardType="email-address"
                  mode="outlined"
                />
              </Animatable.View>
              <Animatable.View animation="fadeInUp" delay={600}>
                <TextInput
                  label="Password"
                  value={password}
                  onChangeText={setPassword}
                  style={styles.input}
                  secureTextEntry={!isPasswordVisible}
                  mode="outlined"
                  right={
                    <TextInput.Icon
                      icon={isPasswordVisible ? "eye-off" : "eye"}
                      onPress={() => setIsPasswordVisible(!isPasswordVisible)}
                    />
                  }
                />
              </Animatable.View>

              <Animatable.View animation="fadeInUp" delay={700}>
                <Button
                  mode="contained"
                  onPress={handleLogin}
                  loading={isEmailLoading}
                  disabled={isLoading}
                  style={styles.button}
                  contentStyle={styles.buttonContent}
                >
                  Masuk
                </Button>
              </Animatable.View>

              <Animatable.View
                animation="fadeInUp"
                delay={800}
                style={styles.dividerContainer}
              >
                <Divider style={styles.divider} />
                <Text style={styles.dividerText}>ATAU</Text>
                <Divider style={styles.divider} />
              </Animatable.View>

              <Animatable.View animation="fadeInUp" delay={900}>
                <Button
                  mode="outlined"
                  icon="google"
                  onPress={() => promptGoogleLogin()}
                  loading={isGoogleLoading}
                  disabled={isLoading}
                  style={styles.googleButton}
                >
                  Masuk dengan Google
                </Button>
              </Animatable.View>

              {isBiometricEnabled && (
                <Animatable.View animation="fadeIn" delay={1100}>
                  <TouchableOpacity
                    onPress={handleBiometricLogin}
                    style={styles.biometricButton}
                  >
                    <Icon
                      source="fingerprint"
                      size={32}
                      color={theme.colors.primary}
                    />
                  </TouchableOpacity>
                </Animatable.View>
              )}
            </Card.Content>
          </Card>
        </Animatable.View>
      </KeyboardAvoidingView>
    </LinearGradient>
  );
};

const styles = StyleSheet.create({
  container: { flex: 1 },
  keyboardView: { flex: 1, justifyContent: "center", padding: 20 },
  card: { borderRadius: 12, backgroundColor: "rgba(255, 255, 255, 0.95)" },
  title: { fontWeight: "bold", textAlign: "center", marginBottom: 8 },
  subtitle: { textAlign: "center", marginBottom: 24 },
  input: { marginBottom: 12 },
  button: { marginTop: 8, borderRadius: 8 },
  buttonContent: { paddingVertical: 8 },
  dividerContainer: {
    flexDirection: "row",
    alignItems: "center",
    marginVertical: 16,
  },
  divider: { flex: 1 },
  dividerText: { marginHorizontal: 8, color: "grey" },
  googleButton: { borderRadius: 8, paddingVertical: 2 },
  biometricButton: {
    alignItems: "center",
    justifyContent: "center",
    marginTop: 20,
    paddingTop: 10,
    borderTopWidth: 1,
    borderTopColor: "#eee",
  },
});
