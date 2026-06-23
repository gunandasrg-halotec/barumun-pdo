#!/bin/bash
set -e

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

PROJECT_DIR="/var/www/pdo.barumun-plantation.com"
DOCKER_COMPOSE_FILE="$PROJECT_DIR/docker-compose.prod.yml"

echo -e "${YELLOW}=== PDO Barumun Deployment Started ===${NC}"
echo "Time: $(date)"
echo ""

# Navigate to project directory
cd "$PROJECT_DIR" || exit 1

# Pull latest code
echo -e "${YELLOW}[1/5] Pulling latest code from main...${NC}"
git fetch origin
git reset --hard origin/main
echo -e "${GREEN}✓ Code pulled successfully${NC}"
echo ""

# Log in to GitHub Container Registry
echo -e "${YELLOW}[2/5] Logging in to GitHub Container Registry...${NC}"
echo "$GITHUB_TOKEN" | docker login ghcr.io -u "$GITHUB_ACTOR" --password-stdin
echo -e "${GREEN}✓ GHCR login successful${NC}"
echo ""

# Pull latest images
echo -e "${YELLOW}[3/5] Pulling latest Docker images...${NC}"
docker pull "${REGISTRY}/${IMAGE_NAME_API}:main"
docker pull "${REGISTRY}/${IMAGE_NAME_WEB}:main"
echo -e "${GREEN}✓ Images pulled successfully${NC}"
echo ""

# Stop and remove old containers
echo -e "${YELLOW}[4/5] Redeploying containers...${NC}"
docker compose -f "$DOCKER_COMPOSE_FILE" down --remove-orphans || true
sleep 2

# Tag images for docker-compose
docker tag "${REGISTRY}/${IMAGE_NAME_API}:main" pdobarumun-plantationcom-api:latest
docker tag "${REGISTRY}/${IMAGE_NAME_WEB}:main" pdobarumun-plantationcom-web:latest

# Start containers with new images
docker compose -f "$DOCKER_COMPOSE_FILE" up -d api web redis
echo -e "${GREEN}✓ Containers redeployed successfully${NC}"
echo ""

# Wait for services to be ready
echo -e "${YELLOW}[5/5] Waiting for services to be ready...${NC}"
sleep 5

# Check container status
API_STATUS=$(docker compose -f "$DOCKER_COMPOSE_FILE" ps api --format json | jq -r '.[0].State')
WEB_STATUS=$(docker compose -f "$DOCKER_COMPOSE_FILE" ps web --format json | jq -r '.[0].State')

if [ "$API_STATUS" = "running" ] && [ "$WEB_STATUS" = "running" ]; then
    echo -e "${GREEN}✓ All containers are running${NC}"
else
    echo -e "${RED}✗ Container status check failed${NC}"
    echo "API Status: $API_STATUS"
    echo "Web Status: $WEB_STATUS"
    exit 1
fi

# Logout from GHCR
docker logout ghcr.io || true

echo ""
echo -e "${GREEN}=== Deployment Completed Successfully ===${NC}"
echo "Timestamp: $(date)"
exit 0
