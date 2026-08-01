#!/bin/bash
# Redis Sentinel Cluster Verification Script
# This script verifies the Redis Sentinel cluster is properly configured and operational

echo "========================================"
echo "Redis Sentinel Cluster Verification"
echo "========================================"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Load environment variables
if [ -f ".env" ]; then
    export $(cat .env | grep -v '^#' | xargs)
else
    echo -e "${RED}✗ .env file not found${NC}"
    exit 1
fi

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo -e "${RED}✗ Docker is not running${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Docker is running${NC}"

# Function to check container status
check_container() {
    local container=$1
    if docker ps --format '{{.Names}}' | grep -q "^${container}$"; then
        echo -e "${GREEN}✓ ${container} is running${NC}"
        return 0
    else
        echo -e "${RED}✗ ${container} is not running${NC}"
        return 1
    fi
}

# Function to check Redis connection
check_redis_connection() {
    local container=$1
    local port=$2
    if docker exec $container redis-cli -a "$REDIS_PASSWORD" -p $port ping > /dev/null 2>&1; then
        echo -e "${GREEN}✓ ${container} is responding${NC}"
        return 0
    else
        echo -e "${RED}✗ ${container} is not responding${NC}"
        return 1
    fi
}

echo ""
echo "Checking Redis Nodes..."
echo "------------------------"
check_container "redis-master"
check_container "redis-replica-1"
check_container "redis-replica-2"

echo ""
echo "Checking Sentinel Nodes..."
echo "---------------------------"
check_container "redis-sentinel-1"
check_container "redis-sentinel-2"
check_container "redis-sentinel-3"

echo ""
echo "Checking Redis Connectivity..."
echo "-------------------------------"
check_redis_connection "redis-master" 6379
check_redis_connection "redis-replica-1" 6379
check_redis_connection "redis-replica-2" 6379

echo ""
echo "Checking Sentinel Connectivity..."
echo "----------------------------------"
if docker exec redis-sentinel-1 redis-cli -p 26379 ping > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Sentinel 1 is responding${NC}"
else
    echo -e "${RED}✗ Sentinel 1 is not responding${NC}"
fi

if docker exec redis-sentinel-2 redis-cli -p 26379 ping > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Sentinel 2 is responding${NC}"
else
    echo -e "${RED}✗ Sentinel 2 is not responding${NC}"
fi

if docker exec redis-sentinel-3 redis-cli -p 26379 ping > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Sentinel 3 is responding${NC}"
else
    echo -e "${RED}✗ Sentinel 3 is not responding${NC}"
fi

echo ""
echo "Checking Replication Status..."
echo "-------------------------------"
MASTER_INFO=$(docker exec redis-master redis-cli -a "$REDIS_PASSWORD" INFO replication 2>/dev/null)
CONNECTED_SLAVES=$(echo "$MASTER_INFO" | grep "connected_slaves" | cut -d: -f2 | tr -d '\r')

if [ "$CONNECTED_SLAVES" = "2" ]; then
    echo -e "${GREEN}✓ Master has 2 connected replicas${NC}"
else
    echo -e "${YELLOW}⚠ Master has $CONNECTED_SLAVES connected replicas (expected 2)${NC}"
fi

echo ""
echo "Checking Sentinel Master Discovery..."
echo "--------------------------------------"
SENTINEL_MASTER=$(docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL get-master-addr-by-name mymaster 2>/dev/null)
if [ -n "$SENTINEL_MASTER" ]; then
    echo -e "${GREEN}✓ Sentinel can discover master${NC}"
    echo "  Master address: $(echo $SENTINEL_MASTER | tr '\n' ':' | sed 's/:$//')"
else
    echo -e "${RED}✗ Sentinel cannot discover master${NC}"
fi

echo ""
echo "Checking Sentinel Quorum..."
echo "---------------------------"
QUORUM_CHECK=$(docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL ckquorum mymaster 2>/dev/null)
if echo "$QUORUM_CHECK" | grep -q "OK"; then
    echo -e "${GREEN}✓ Sentinel quorum is satisfied${NC}"
else
    echo -e "${RED}✗ Sentinel quorum is not satisfied${NC}"
fi

echo ""
echo "Checking Data Persistence..."
echo "----------------------------"
# Write test key
docker exec redis-master redis-cli -a "$REDIS_PASSWORD" SET test_key "test_value" > /dev/null 2>&1
sleep 1

# Read from replica
REPLICA_VALUE=$(docker exec redis-replica-1 redis-cli -a "$REDIS_PASSWORD" GET test_key 2>/dev/null)
if [ "$REPLICA_VALUE" = "test_value" ]; then
    echo -e "${GREEN}✓ Data is replicating to replicas${NC}"
else
    echo -e "${RED}✗ Data is not replicating properly${NC}"
fi

# Cleanup test key
docker exec redis-master redis-cli -a "$REDIS_PASSWORD" DEL test_key > /dev/null 2>&1

echo ""
echo "Checking Memory Usage..."
echo "------------------------"
MASTER_MEMORY=$(docker exec redis-master redis-cli -a "$REDIS_PASSWORD" INFO memory 2>/dev/null | grep "used_memory_human" | cut -d: -f2 | tr -d '\r')
echo "  Master memory usage: $MASTER_MEMORY"

REPLICA1_MEMORY=$(docker exec redis-replica-1 redis-cli -a "$REDIS_PASSWORD" INFO memory 2>/dev/null | grep "used_memory_human" | cut -d: -f2 | tr -d '\r')
echo "  Replica 1 memory usage: $REPLICA1_MEMORY"

REPLICA2_MEMORY=$(docker exec redis-replica-2 redis-cli -a "$REDIS_PASSWORD" INFO memory 2>/dev/null | grep "used_memory_human" | cut -d: -f2 | tr -d '\r')
echo "  Replica 2 memory usage: $REPLICA2_MEMORY"

echo ""
echo "Checking Persistence Configuration..."
echo "--------------------------------------"
AOF_STATUS=$(docker exec redis-master redis-cli -a "$REDIS_PASSWORD" CONFIG GET appendonly 2>/dev/null | tail -1)
if [ "$AOF_STATUS" = "yes" ]; then
    echo -e "${GREEN}✓ AOF persistence is enabled${NC}"
else
    echo -e "${YELLOW}⚠ AOF persistence is disabled${NC}"
fi

RDB_STATUS=$(docker exec redis-master redis-cli -a "$REDIS_PASSWORD" CONFIG GET save 2>/dev/null | tail -1)
if [ -n "$RDB_STATUS" ] && [ "$RDB_STATUS" != '""' ]; then
    echo -e "${GREEN}✓ RDB persistence is enabled${NC}"
else
    echo -e "${YELLOW}⚠ RDB persistence is disabled${NC}"
fi

echo ""
echo "Checking Authentication..."
echo "--------------------------"
# Try to connect without password (should fail)
if docker exec redis-master redis-cli ping > /dev/null 2>&1; then
    echo -e "${RED}✗ Authentication is not enforced${NC}"
else
    echo -e "${GREEN}✓ Authentication is enforced${NC}"
fi

echo ""
echo "========================================"
echo "Verification Complete"
echo "========================================"
echo ""
echo "Summary:"
echo "--------"
echo "Run 'docker-compose -f docker-compose.redis-sentinel.yml ps' to see all containers"
echo "Run 'docker-compose -f docker-compose.redis-sentinel.yml logs -f' to view logs"
echo ""
echo "To test failover manually:"
echo "  docker stop redis-master"
echo "  docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL get-master-addr-by-name mymaster"
echo "  docker start redis-master"
echo ""
