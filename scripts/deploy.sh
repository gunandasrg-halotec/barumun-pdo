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
echo -e "${YELLOW}[1/4] Pulling latest code from GitHub...${NC}"
git fetch origin
git reset --hard origin/main
echo -e "${GREEN}✓ Code pulled ($(git log --oneline -1))${NC}"
echo ""

# Step 2: Manage containers
echo -e "${YELLOW}[2/4] Stopping existing containers...${NC}"
docker compose -f "$DOCKER_COMPOSE_FILE" down --remove-orphans || true
sleep 2
echo ""

# Step 3: Start containers (build only if images missing)
echo -e "${YELLOW}[3/4] Starting containers...${NC}"
if docker compose -f "$DOCKER_COMPOSE_FILE" up -d --no-build 2>&1 | grep -q "image not found\|No such image"; then
  echo "Images not found, building..."
  docker compose -f "$DOCKER_COMPOSE_FILE" build
  docker compose -f "$DOCKER_COMPOSE_FILE" up -d --no-build
fi
echo -e "${GREEN}✓ Containers started${NC}"
echo ""

# Step 4: Wait for containers to stabilize and verify
echo -e "${YELLOW}[4/4] Verifying containers are running...${NC}"
sleep 15

API_RUNNING=$(docker compose -f "$DOCKER_COMPOSE_FILE" ps api 2>/dev/null | grep -c "running" || echo "0")
WEB_RUNNING=$(docker compose -f "$DOCKER_COMPOSE_FILE" ps web 2>/dev/null | grep -c "running" || echo "0")

if [ "$API_RUNNING" = "1" ] && [ "$WEB_RUNNING" = "1" ]; then
    echo -e "${GREEN}✓ All containers running${NC}"
    echo ""
    echo -e "${GREEN}=== ✅ Deployment Completed Successfully ===${NC}"
    echo "Completed at: $(date)"
    exit 0
else
    echo -e "${RED}✗ Container health check failed${NC}"
    echo "API Running: $API_RUNNING"
    echo "Web Running: $WEB_RUNNING"
    echo ""
    echo "Logs:"
    docker compose -f "$DOCKER_COMPOSE_FILE" logs api web | tail -50
    exit 1
fi
