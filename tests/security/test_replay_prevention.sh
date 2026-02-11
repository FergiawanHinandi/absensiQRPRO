#!/bin/bash

# Test Script untuk Replay Attack Prevention
# Author: Security Team
# Version: 1.0.0

API_URL="http://localhost:8000/api/v1"
TOKEN="YOUR_AUTH_TOKEN_HERE"

echo "🧪 Testing Replay Attack Prevention"
echo "===================================="
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test 1: Idempotency - Same Key
echo -e "${YELLOW}Test 1: Idempotency - Same Key${NC}"
echo "Sending 2 requests with SAME idempotency key..."

IDEMPOTENCY_KEY=$(uuidgen)
echo "Idempotency Key: $IDEMPOTENCY_KEY"

# First request
echo ""
echo "Request 1:"
RESPONSE1=$(curl -s -X POST "$API_URL/attendance/scan" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $IDEMPOTENCY_KEY" \
  -d '{
    "qr_payload": "test_payload_1",
    "lat": -6.2088,
    "lng": 106.8456
  }')

echo "$RESPONSE1" | jq '.'

# Second request (should return cached response)
sleep 1
echo ""
echo "Request 2 (with SAME key):"
RESPONSE2=$(curl -s -X POST "$API_URL/attendance/scan" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $IDEMPOTENCY_KEY" \
  -d '{
    "qr_payload": "test_payload_1",
    "lat": -6.2088,
    "lng": 106.8456
  }')

echo "$RESPONSE2" | jq '.'

# Check if second response has _idempotent_replay flag
if echo "$RESPONSE2" | jq -e '._idempotent_replay' > /dev/null; then
    echo -e "${GREEN}✓ Test 1 PASSED: Idempotency working correctly${NC}"
else
    echo -e "${RED}✗ Test 1 FAILED: Idempotency not working${NC}"
fi

echo ""
echo "---"
echo ""

# Test 2: Rate Limiting
echo -e "${YELLOW}Test 2: Rate Limiting${NC}"
echo "Sending 12 requests in quick succession (limit: 10/min)..."

SUCCESS_COUNT=0
RATE_LIMITED_COUNT=0

for i in {1..12}; do
    UNIQUE_KEY=$(uuidgen)
    RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$API_URL/attendance/scan" \
      -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" \
      -H "X-Idempotency-Key: $UNIQUE_KEY" \
      -d '{
        "qr_payload": "test_payload_'$i'",
        "lat": -6.2088,
        "lng": 106.8456
      }')
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
    
    if [ "$HTTP_CODE" == "429" ]; then
        RATE_LIMITED_COUNT=$((RATE_LIMITED_COUNT + 1))
        echo "Request $i: Rate Limited (429)"
    else
        SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
        echo "Request $i: Success ($HTTP_CODE)"
    fi
    
    sleep 0.5
done

echo ""
echo "Results:"
echo "  Success: $SUCCESS_COUNT"
echo "  Rate Limited: $RATE_LIMITED_COUNT"

if [ $RATE_LIMITED_COUNT -gt 0 ]; then
    echo -e "${GREEN}✓ Test 2 PASSED: Rate limiting working${NC}"
else
    echo -e "${RED}✗ Test 2 FAILED: Rate limiting not working${NC}"
fi

echo ""
echo "---"
echo ""

# Test 3: Invalid Idempotency Key Format
echo -e "${YELLOW}Test 3: Invalid Idempotency Key Format${NC}"
echo "Sending request with invalid key format..."

RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$API_URL/attendance/scan" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: invalid-key-format" \
  -d '{
    "qr_payload": "test_payload",
    "lat": -6.2088,
    "lng": 106.8456
  }')

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | head -n -1)

echo "$BODY" | jq '.'

if [ "$HTTP_CODE" == "400" ]; then
    echo -e "${GREEN}✓ Test 3 PASSED: Invalid key rejected${NC}"
else
    echo -e "${RED}✗ Test 3 FAILED: Invalid key not rejected (HTTP $HTTP_CODE)${NC}"
fi

echo ""
echo "---"
echo ""

# Test 4: Rate Limit Headers
echo -e "${YELLOW}Test 4: Rate Limit Headers${NC}"
echo "Checking rate limit headers in response..."

HEADERS=$(curl -s -D - -X POST "$API_URL/attendance/scan" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -d '{
    "qr_payload": "test_payload",
    "lat": -6.2088,
    "lng": 106.8456
  }' | grep -i "x-ratelimit")

echo "$HEADERS"

if echo "$HEADERS" | grep -q "X-RateLimit-Limit"; then
    echo -e "${GREEN}✓ Test 4 PASSED: Rate limit headers present${NC}"
else
    echo -e "${RED}✗ Test 4 FAILED: Rate limit headers missing${NC}"
fi

echo ""
echo "===================================="
echo "🎉 Testing Complete!"
