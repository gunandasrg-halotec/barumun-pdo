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
echo -e "${GREEN}✓ Code pulled${NC}"
echo ""

# Step 2: Build Docker images
echo -e "${YELLOW}[2/4] Building Docker images...${NC}"
docker compose -f "$DOCKER_COMPOSE_FILE" build api web
echo -e "${GREEN}✓ Docker images built${NC}"
echo ""

# Step 3: Start containers
echo -e "${YELLOW}[3/4] Starting containers...${NC}"
docker compose -f "$DOCKER_COMPOSE_FILE" down --remove-orphans || true
sleep 2
docker compose -f "$DOCKER_COMPOSE_FILE" up -d api web redis
echo -e "${GREEN}✓ Containers started${NC}"
echo ""

# Step 4: Wait for services to be ready
echo -e "${YELLOW}[4/4] Waiting for services to be ready...${NC}"
sleep 5

# Verify container status
API_STATUS=$(docker compose -f "$DOCKER_COMPOSE_FILE" ps api --format json 2>/dev/null | jq -r '.[0].State' || echo "unknown")
WEB_STATUS=$(docker compose -f "$DOCKER_COMPOSE_FILE" ps web --format json 2>/dev/null | jq -r '.[0].State' || echo "unknown")

if [ "$API_STATUS" = "running" ] && [ "$WEB_STATUS" = "running" ]; then
    echo -e "${GREEN}✓ All containers running${NC}"
    echo ""
    echo -e "${GREEN}=== Deployment Completed Successfully ===${NC}"
    echo "Completed at: $(date)"
    exit 0
else
    echo -e "${RED}✗ Container health check failed${NC}"
    echo "API Status: $API_STATUS"
    echo "Web Status: $WEB_STATUS"
    echo ""
    echo "Logs:"
    docker compose -f "$DOCKER_COMPOSE_FILE" logs api web | tail -20
    exit 1
fi
