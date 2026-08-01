#!/bin/bash
# =============================================================================
# Android Keystore Generator untuk AbsensiQRPro
# Usage: ./deploy/generate-keystore.sh
# =============================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configuration
KEYSTORE_DIR="AbsensiQRMobile/android/app"
KEYSTORE_FILE="$KEYSTORE_DIR/keystore.jks"
PROPERTIES_FILE="$KEYSTORE_DIR/keystore.properties"

echo -e "${CYAN}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║       🔐 Android Keystore Generator for AbsensiQRPro 🔐     ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════════════════════╝${NC}"
echo

# Check if keytool is available
if ! command -v keytool &> /dev/null; then
    echo -e "${RED}[ERROR]${NC} keytool not found!"
    echo -e "${YELLOW}[INFO]${NC}  Install Java JDK to get keytool."
    echo -e "${YELLOW}[INFO]${NC}  On Ubuntu: sudo apt install openjdk-17-jdk"
    echo -e "${YELLOW}[INFO]${NC}  On macOS: brew install openjdk"
    exit 1
fi

# Check if keystore already exists
if [ -f "$KEYSTORE_FILE" ]; then
    echo -e "${YELLOW}[WARNING]${NC} Keystore already exists: $KEYSTORE_FILE"
    read -p "Do you want to overwrite it? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Aborted."
        exit 1
    fi
    # Backup existing
    mv "$KEYSTORE_FILE" "$KEYSTORE_FILE.backup.$(date +%Y%m%d_%H%M%S)"
    echo -e "${GREEN}[INFO]${NC} Existing keystore backed up."
fi

echo -e "${BLUE}[STEP 1/3]${NC} Keystore Information"
echo

# Get keystore information
read -p "$(echo -e "${BLUE}Enter your name or organization: ${NC}")" ORGANIZATION
ORGANIZATION=${ORGANIZATION:-"AbsensiQRPro"}

read -p "$(echo -e "${BLUE}Enter organizational unit (optional): ${NC}")" ORG_UNIT
ORG_UNIT=${ORG_UNIT:-"Development"}

read -p "$(echo -e "${BLUE}Enter city or locality: ${NC}")" CITY
CITY=${CITY:-"Jakarta"}

read -p "$(echo -e "${BLUE}Enter state or province: ${NC}")" STATE
STATE=${STATE:-"DKI Jakarta"}

read -p "$(echo -e "${BLUE}Enter country code (2 letters): ${NC}")" COUNTRY
COUNTRY=${COUNTRY:-"ID"}

echo
echo -e "${BLUE}[STEP 2/3]${NC} Password Configuration"
echo

# Get passwords
read -s -p "$(echo -e "${BLUE}Enter keystore password: ${NC}")" STORE_PASSWORD
echo
if [ -z "$STORE_PASSWORD" ]; then
    echo -e "${RED}[ERROR]${NC} Keystore password cannot be empty!"
    exit 1
fi

read -s -p "$(echo -e "${BLUE}Confirm keystore password: ${NC}")" STORE_PASSWORD_CONFIRM
echo

if [ "$STORE_PASSWORD" != "$STORE_PASSWORD_CONFIRM" ]; then
    echo -e "${RED}[ERROR]${NC} Passwords do not match!"
    exit 1
fi

read -s -p "$(echo -e "${BLUE}Enter key alias (default: release): ${NC}")" KEY_ALIAS
echo
KEY_ALIAS=${KEY_ALIAS:-release}

read -s -p "$(echo -e "${BLUE}Enter key password: ${NC}")" KEY_PASSWORD
echo
if [ -z "$KEY_PASSWORD" ]; then
    KEY_PASSWORD=$STORE_PASSWORD
    echo -e "${YELLOW}[INFO]${NC} Key password set to same as keystore password."
fi

echo
echo -e "${BLUE}[STEP 3/3]${NC} Generating Keystore"
echo

# Create directory if not exists
mkdir -p "$KEYSTORE_DIR"

# Generate keystore
keytool -genkeypair \
    -v \
    -keystore "$KEYSTORE_FILE" \
    -alias "$KEY_ALIAS" \
    -keyalg RSA \
    -keysize 2048 \
    -validity 10000 \
    -storepass "$STORE_PASSWORD" \
    -keypass "$KEY_PASSWORD" \
    -dname "CN=$ORGANIZATION, OU=$ORG_UNIT, L=$CITY, ST=$STATE, C=$COUNTRY"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}[SUCCESS]${NC} Keystore generated successfully!"
    echo
    
    # Create keystore.properties
    cat > "$PROPERTIES_FILE" << EOF
storeFile=keystore.jks
storePassword=$STORE_PASSWORD
keyAlias=$KEY_ALIAS
keyPassword=$KEY_PASSWORD
EOF
    
    chmod 600 "$PROPERTIES_FILE"
    
    echo -e "${GREEN}[SUCCESS]${NC} keystore.properties created!"
    echo
    
    # Summary
    echo -e "${CYAN}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║                    KEYSTORE GENERATED!                       ║${NC}"
    echo -e "${CYAN}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo
    echo -e "${GREEN}📋 Keystore Information:${NC}"
    echo -e "${GREEN}  File: $KEYSTORE_FILE${NC}"
    echo -e "${GREEN}  Properties: $PROPERTIES_FILE${NC}"
    echo -e "${GREEN}  Alias: $KEY_ALIAS${NC}"
    echo -e "${GREEN}  Validity: 10000 days (~27 years)${NC}"
    echo
    echo -e "${YELLOW}⚠️  PENTING:${NC}"
    echo -e "${YELLOW}  1. SIMPAN FILE keystore.jks DI TEMPAT YANG AMAN!${NC}"
    echo -e "${YELLOW}  2. JANGAN commit keystore.jks ke git!${NC}"
    echo -e "${YELLOW}  3. JANGAN commit keystore.properties ke git!${NC}"
    echo -e "${YELLOW}  4. Buat backup keystore di tempat terpisah!${NC}"
    echo -e "${YELLOW}  5. Jika keystore hilang, Anda TIDAK BISA update app di Play Store!${NC}"
    echo
    echo -e "${BLUE}📋 Langkah Selanjutnya:${NC}"
    echo -e "${BLUE}  1. Build release APK: cd AbsensiQRMobile && npm run android --mode=release${NC}"
    echo -e "${BLUE}  2. Atau build AAB untuk Play Store: cd AbsensiQRMobile/android && ./gradlew bundleRelease${NC}"
    echo
else
    echo -e "${RED}[ERROR]${NC} Failed to generate keystore!"
    exit 1
fi
