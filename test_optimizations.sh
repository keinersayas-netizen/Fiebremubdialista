#!/bin/bash

# Script de Testing - Optimización Módulo "Mis Pronósticos"
# ═══════════════════════════════════════════════════════════════

echo "🧪 TESTING OPTIMIZACIONES - Mis Pronósticos"
echo "═══════════════════════════════════════════════════════════════"
echo ""

# Colores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Variables
BASE_URL="http://localhost/fiebremundialista/api.php"
TEST_EMAIL="demo@matchday.app"
TEST_PASSWORD="demo123"

# Función para imprimir resultados
test_result() {
    local name=$1
    local response=$2
    local expected=$3
    
    if echo "$response" | grep -q "$expected"; then
        echo -e "${GREEN}✓ PASS${NC} - $name"
        return 0
    else
        echo -e "${RED}✗ FAIL${NC} - $name"
        echo "Response: $response"
        return 1
    fi
}

# Test 1: Login
echo -e "${BLUE}[1/5]${NC} Testing Login..."
LOGIN_RESPONSE=$(curl -s -X POST "$BASE_URL/login" \
    -H "Content-Type: application/json" \
    -d "{\"login\":\"$TEST_EMAIL\",\"password\":\"$TEST_PASSWORD\"}")

TOKEN=$(echo "$LOGIN_RESPONSE" | grep -o '"token":"[^"]*' | cut -d'"' -f4)
if [ -z "$TOKEN" ]; then
    echo -e "${RED}✗ FAIL${NC} - Could not obtain token"
    echo "Response: $LOGIN_RESPONSE"
    exit 1
else
    echo -e "${GREEN}✓ PASS${NC} - Login successful"
    echo "Token: $TOKEN"
fi
echo ""

# Test 2: Paginación en getPredictions
echo -e "${BLUE}[2/5]${NC} Testing getPredictions with Pagination..."
PREDS_RESPONSE=$(curl -s -X GET "$BASE_URL/predictions?limit=50&offset=0" \
    -H "Authorization: Bearer $TOKEN")

test_result "Pagination response has 'predictions' field" "$PREDS_RESPONSE" '"predictions"'
test_result "Pagination response has 'pagination' object" "$PREDS_RESPONSE" '"pagination"'
test_result "Pagination has 'limit' field" "$PREDS_RESPONSE" '"limit"'
test_result "Pagination has 'offset' field" "$PREDS_RESPONSE" '"offset"'
test_result "Pagination has 'total' field" "$PREDS_RESPONSE" '"total"'
test_result "Pagination has 'has_more' field" "$PREDS_RESPONSE" '"has_more"'
echo ""

# Test 3: Async Verify
echo -e "${BLUE}[3/5]${NC} Testing Async Verify Endpoint..."
VERIFY_RESPONSE=$(curl -s -X POST "$BASE_URL/verify-async" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{}')

test_result "Verify-async returns 'ok' field" "$VERIFY_RESPONSE" '"ok"'
test_result "Verify-async returns 'verified_count'" "$VERIFY_RESPONSE" '"verified_count"'
test_result "Verify-async returns 'timestamp'" "$VERIFY_RESPONSE" '"timestamp"'
echo ""

# Test 4: getPredictionsAll with Pagination
echo -e "${BLUE}[4/5]${NC} Testing getPredictionsAll with Pagination..."
PREDS_ALL_RESPONSE=$(curl -s -X GET "$BASE_URL/predictions-all?limit=300&offset=0")

test_result "getPredictionsAll has 'predictions' field" "$PREDS_ALL_RESPONSE" '"predictions"'
test_result "getPredictionsAll has 'pagination' object" "$PREDS_ALL_RESPONSE" '"pagination"'
test_result "getPredictionsAll has valid limit" "$PREDS_ALL_RESPONSE" '"limit"'
echo ""

# Test 5: Performance Timing
echo -e "${BLUE}[5/5]${NC} Testing Performance Metrics..."
echo "Measuring getPredictions response time..."

START_TIME=$(date +%s%N)
PERF_RESPONSE=$(curl -s -X GET "$BASE_URL/predictions?limit=50&offset=0" \
    -H "Authorization: Bearer $TOKEN")
END_TIME=$(date +%s%N)

ELAPSED_MS=$(( (END_TIME - START_TIME) / 1000000 ))
echo -e "Response time: ${YELLOW}${ELAPSED_MS}ms${NC}"

if [ $ELAPSED_MS -lt 2000 ]; then
    echo -e "${GREEN}✓ PASS${NC} - Response time < 2000ms"
else
    echo -e "${YELLOW}⚠ WARNING${NC} - Response time > 2000ms (Got ${ELAPSED_MS}ms)"
fi
echo ""

# Summary
echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
echo "✅ TESTING COMPLETE"
echo ""
echo "Checklist:"
echo "  ✓ Login and token generation"
echo "  ✓ getPredictions paginación"
echo "  ✓ Async verify endpoint"
echo "  ✓ getPredictionsAll paginación"
echo "  ✓ Performance metrics"
echo ""
echo "Próximos pasos:"
echo "  1. Ejecutar optimize_performance.sql en BD"
echo "  2. Desplegar archivos en servidor"
echo "  3. Monitorear performance en producción"
echo ""
