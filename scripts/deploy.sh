#!/bin/bash
# Production deploy for PDO Barumun.
#
# Safety guarantees:
#  - NEVER touches Docker volumes (no `down -v`). The production database is AWS
#    RDS reached via host pgbouncer; nothing here can delete data.
#  - Rebuilds images so code changes actually take effect.
#  - `up -d` recreates only changed containers (near-zero downtime); it does not
#    stop the stack first.
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

PROJECT_DIR="/var/www/pdo.barumun-plantation.com"
COMPOSE="docker compose -f $PROJECT_DIR/docker-compose.prod.yml"

cd "$PROJECT_DIR"

echo -e "${YELLOW}=== PDO Barumun Production Deployment ===${NC}"
echo "Started at: $(date)"

echo -e "${YELLOW}[1/4] Pulling latest code...${NC}"
git fetch origin
git reset --hard origin/main
echo -e "${GREEN}✓ $(git log --oneline -1)${NC}"

echo -e "${YELLOW}[2/4] Building images...${NC}"
$COMPOSE build

echo -e "${YELLOW}[3/4] Applying (recreate changed containers, volumes untouched)...${NC}"
$COMPOSE up -d

echo -e "${YELLOW}[4/4] Verifying...${NC}"
sleep 10
$COMPOSE ps

web_code=$(curl -fsS -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/ || echo 000)
api_code=$(curl -fsS -o /dev/null -w '%{http_code}' -X POST http://127.0.0.1:8000/api/v1/auth/login \
            -H 'Content-Type: application/json' -d '{}' || echo 000)
echo "web=$web_code api=$api_code (api 422 = healthy validation response)"

if [ "$web_code" = "200" ] && { [ "$api_code" = "422" ] || [ "$api_code" = "401" ]; }; then
  echo -e "${GREEN}=== ✅ Deployment OK ===${NC}"
  exit 0
fi
echo -e "${RED}✗ Health check failed${NC}"
$COMPOSE logs api web | tail -50
exit 1
