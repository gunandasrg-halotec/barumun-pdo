#!/bin/bash

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

HEALTH_CHECK_URL="https://pdo.barumun-plantation.com"
MAX_RETRIES=5
RETRY_DELAY=3
TIMEOUT=10

echo -e "${YELLOW}=== Running Health Checks ===${NC}"
echo ""

# Function to check HTTP status
check_http_status() {
    local url=$1
    local expected_status=$2
    local description=$3
    
    echo -n "Checking $description... "
    
    for i in $(seq 1 $MAX_RETRIES); do
        STATUS=$(curl -s -o /dev/null -w "%{http_code}" -m $TIMEOUT "$url" 2>/dev/null || echo "000")
        
        if [ "$STATUS" -eq 200 ]; then
            echo -e "${GREEN}✓ OK (HTTP $STATUS)${NC}"
            return 0
        fi
        
        if [ $i -lt $MAX_RETRIES ]; then
            echo -n "retry... "
            sleep $RETRY_DELAY
        fi
    done
    
    echo -e "${RED}✗ FAILED (HTTP $STATUS)${NC}"
    return 1
}

# Check Web UI
echo -e "${YELLOW}Web UI Check:${NC}"
check_http_status "$HEALTH_CHECK_URL" 200 "Web UI"
WEB_RESULT=$?
echo ""

# Check API endpoints
echo -e "${YELLOW}API Endpoint Checks:${NC}"

# API health endpoint (basic connectivity)
check_http_status "$HEALTH_CHECK_URL/api/v1" 404 "API base (404 expected)"
API_RESULT=$?

# Try a simple auth check endpoint
echo -n "Checking API sanity (no auth required)... "
API_RESPONSE=$(curl -s -m $TIMEOUT "$HEALTH_CHECK_URL/api/v1/" 2>/dev/null || echo "")
if [ ! -z "$API_RESPONSE" ]; then
    echo -e "${GREEN}✓ Responding${NC}"
else
    echo -e "${RED}✗ No response${NC}"
fi
echo ""

# Summary
echo -e "${YELLOW}=== Health Check Summary ===${NC}"
if [ $WEB_RESULT -eq 0 ] && [ $API_RESULT -eq 0 ]; then
    echo -e "${GREEN}✓ All health checks passed${NC}"
    exit 0
else
    echo -e "${RED}✗ Some health checks failed${NC}"
    exit 1
fi
