# Drozast Dashboard

Panel de servicios y monitoreo para el servidor Drozast.

## Componentes

- `index.html` - Dashboard principal con panel de monitoreo en tiempo real
- `api/index.php` - API de estadísticas del servidor

## URLs

- Dashboard: https://drozast.xyz
- Stats API: https://stats.drozast.xyz

## Características

- Monitoreo en tiempo real (CPU, RAM, Disco, Red)
- Consumo energético estimado
- Enlaces a todos los servicios
- Actualización automática cada 5 segundos

## Servicios Monitoreados

- FROGIO (API Backend, ntfy, MinIO)
- Mise en Place
- Nextcloud, Stirling PDF
- Navidrome, Kavita, MeTube
- Coolify, Portainer, Pi-hole
- Vaultwarden, Shlink
- Mapas Self-Hosted (TileServer GL, Nominatim, OSRM)

## Mapas Self-Hosted (Alternativa a Google Maps)

Stack de mapas local para Chile, sin costo por request.

| Servicio | Funcion | Subdominio | Reemplazo de |
|----------|---------|------------|--------------|
| TileServer GL | Mapas visuales | maps.drozast.xyz | Google Maps JS API |
| Nominatim | Geocoding/busqueda | geo.drozast.xyz | Google Geocoding API |
| OSRM | Calculo de rutas | routing.drozast.xyz | Google Directions API |

### Setup

```bash
cd maps/
./setup.sh
```

### Configuracion Cloudflare Tunnel

Agregar al archivo `~/.cloudflared/config.yml`:

```yaml
- hostname: maps.drozast.xyz
  service: http://localhost:8080
- hostname: geo.drozast.xyz
  service: http://localhost:8081
- hostname: routing.drozast.xyz
  service: http://localhost:5000
```

### Endpoints

**Geocoding:**
- `https://geo.drozast.xyz/search?q={direccion}&format=json&countrycodes=cl`
- `https://geo.drozast.xyz/reverse?lat={lat}&lon={lon}&format=json`

**Routing:**
- `https://routing.drozast.xyz/route/v1/driving/{lon1},{lat1};{lon2},{lat2}?overview=full&geometries=geojson`
- `https://routing.drozast.xyz/table/v1/driving/{coords}`

**Mapas (Leaflet):**
- Tiles: `https://maps.drozast.xyz/styles/osm-bright/{z}/{x}/{y}.png`

### Requisitos del servidor

- RAM: ~4-5 GB adicionales
- Disco: ~5-10 GB (solo Chile)
- Importacion inicial Nominatim: ~1-2 horas
