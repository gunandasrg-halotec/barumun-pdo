#!/bin/bash
set -e

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

PROJECT_DIR="/var/www/pdo.barumun-plantation.com"
DOCKER_COMPOSE_FILE="$PROJECT_DIR/docker-compose.prod.yml"

echo -e "${YELLOW}=== PDO Barumun Production Deployment ===${NC}"
echo "Started at: $(date)"
echo ""

cd "$PROJECT_DIR" || exit 1

# Step 1: Pull latest code
echo -e "${YELLOW}[1/3] Pulling latest code from GitHub...${NC}"
git fetch origin
git reset --hard origin/main
echo -e "${GREEN}✓ Code pulled ($(git log --oneline -1))${NC}"
echo ""

# Step 2: Restart containers with existing images
echo -e "${YELLOW}[2/3] Restarting containers...${NC}"
docker compose -f "$DOCKER_COMPOSE_FILE" down --remove-orphans || true
sleep 2
docker compose -f "$DOCKER_COMPOSE_FILE" up -d
echo -e "${GREEN}✓ Containers restarted${NC}"
echo ""

# Step 3: Wait and verify
echo -e "${YELLOW}[3/3] Verifying containers are running...${NC}"
sleep 5

API_STATUS=$(docker compose -f "$DOCKER_COMPOSE_FILE" ps api --format json 2>/dev/null | jq -r '.[0].State' || echo "unknown")
WEB_STATUS=$(docker compose -f "$DOCKER_COMPOSE_FILE" ps web --format json 2>/dev/null | jq -r '.[0].State' || echo "unknown")

if [ "$API_STATUS" = "running" ] && [ "$WEB_STATUS" = "running" ]; then
    echo -e "${GREEN}✓ All containers running${NC}"
    echo ""
    echo -e "${GREEN}=== ✅ Deployment Completed Successfully ===${NC}"
    echo "Completed at: $(date)"
    exit 0
else
    echo -e "${RED}✗ Container health check failed${NC}"
    echo "API Status: $API_STATUS"
    echo "Web Status: $WEB_STATUS"
    echo ""
    echo "Logs:"
    docker compose -f "$DOCKER_COMPOSE_FILE" logs api web | tail -30
    exit 1
fi
