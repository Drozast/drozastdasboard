#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# DROZAST MAPS - Setup Script
# Instala stack de mapas self-hosted (Chile)
# Servicios: TileServer GL, Nominatim, OSRM
# ═══════════════════════════════════════════════════════════════

set -e

MAPS_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$MAPS_DIR"

echo "================================================"
echo "  DROZAST MAPS - Instalacion de Mapas Chile"
echo "================================================"
echo ""

# 1. Crear estructura de carpetas
echo "[1/6] Creando estructura de carpetas..."
mkdir -p tileserver nominatim osrm
echo "  OK"

# 2. Descargar mapa de Chile para OSRM
echo "[2/6] Descargando mapa de Chile (~300MB)..."
if [ ! -f ./osrm/chile-latest.osm.pbf ]; then
    wget -P ./osrm https://download.geofabrik.de/south-america/chile-latest.osm.pbf
    echo "  OK - Mapa descargado"
else
    echo "  SKIP - Mapa ya existe"
fi

# 3. Descargar estilos de mapa para TileServer
echo "[3/6] Descargando estilos de mapa..."
if [ ! -d ./tileserver/osm-bright ]; then
    cd tileserver
    wget -q https://github.com/openmaptiles/osm-bright-gl-style/releases/download/v1.9/osm-bright.zip
    unzip -q osm-bright.zip
    rm -f osm-bright.zip
    cd ..
    echo "  OK - Estilos descargados"
else
    echo "  SKIP - Estilos ya existen"
fi

# 4. Preparar datos OSRM (extract + partition + customize)
echo "[4/6] Preparando datos OSRM (esto puede tardar 10-30 min)..."
if [ ! -f ./osrm/chile-latest.osrm ]; then
    docker compose --profile setup up osrm-prepare
    echo "  OK - Datos OSRM preparados"
else
    echo "  SKIP - Datos OSRM ya preparados"
fi

# 5. Iniciar Nominatim (importacion inicial ~1-2 horas)
echo "[5/6] Iniciando Nominatim (importacion inicial puede tardar 1-2 horas)..."
docker compose up -d nominatim
echo "  OK - Nominatim iniciado (ver progreso: docker logs -f maps-nominatim)"

# 6. Iniciar todos los servicios
echo "[6/6] Iniciando todos los servicios..."
docker compose up -d
echo "  OK - Servicios iniciados"

echo ""
echo "================================================"
echo "  Verificando servicios..."
echo "================================================"
sleep 10

check_service() {
    if curl -s --max-time 5 "$1" > /dev/null 2>&1; then
        echo "  OK  $2 ($1)"
    else
        echo "  WAIT $2 ($1) - aun iniciando..."
    fi
}

check_service "http://localhost:8080" "TileServer GL"
check_service "http://localhost:8081/status" "Nominatim"
check_service "http://localhost:5000" "OSRM"

echo ""
echo "================================================"
echo "  Subdominios Cloudflare (agregar a config.yml):"
echo "================================================"
echo ""
echo "  maps.drozast.xyz    -> http://localhost:8080"
echo "  geo.drozast.xyz     -> http://localhost:8081"
echo "  routing.drozast.xyz -> http://localhost:5000"
echo ""
echo "  Reiniciar tunnel: sudo systemctl restart cloudflared"
echo "================================================"
