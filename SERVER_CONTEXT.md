# DROZAST SERVER - CONTEXTO COMPLETO
> Generado: 2026-02-27 14:05 UTC

## IMPORTANTE - MANTENIMIENTO DEL CONTEXTO
**Cada vez que se agregue, quite o modifique un servicio/contenedor, se DEBE actualizar este archivo.**

---

## SISTEMA

| Propiedad | Valor |
|-----------|-------|
| **Hostname** | server01 |
| **OS** | Ubuntu 25.10 (Questing Quokka) |
| **Kernel** | 6.17.0-14-generic x86_64 |
| **Uptime** | 9 days, 12:29 |
| **IP Local** | 192.168.31.145 (enp6s0) |
| **IP Secundaria** | 192.168.1.7 (enp7s0) |
| **Tailscale** | 100.99.166.80 |

---

## ACCESO

```bash
# SSH Local (misma red)
ssh drozast@192.168.31.145
# Password: 123

# SSH via Cloudflare Tunnel (remoto)
cloudflared access ssh --hostname ssh.drozast.xyz

# SSH via Tailscale
ssh drozast@100.99.166.80
```

---

## HARDWARE

| Componente | Especificacion |
|------------|----------------|
| **CPU** | Intel Xeon E5-2696 v4 @ 2.20GHz |
| **Cores** | 22 cores / 44 threads |
| **Frecuencia** | 1200-3700 MHz (actual ~47%) |
| **RAM** | 31GB DDR4 (23GB usado, 7GB disponible) |
| **Swap** | 4GB (3.6GB usado) |
| **GPU** | AMD Radeon RX 470/480/570/580 (Ellesmere) |

---

## ALMACENAMIENTO

### SSD Sistema (NVMe Fanxiang S790 1TB)
```
/dev/nvme0n1 (931GB fisico)
├── /dev/nvme0n1p1  1GB   /boot/efi    (EFI)
├── /dev/nvme0n1p2  2GB   /boot        (ext4)
└── /dev/nvme0n1p3  444GB /            (LVM: ubuntu--vg-ubuntu--lv)
                          Uso: 131GB/437GB (32%)
```

### RAID1 Datos (2x WDC WD40PURZ 4TB)
```
/dev/sda + /dev/sdb → /dev/md0 → /dev/bcache0
Montaje: /mnt/nextcloud-data
Capacidad: 3.6TB (526GB usado, 2.9TB libre = 16%)
Estado RAID: [UU] (ambos discos OK)
```

---

## SERVICIOS DEL SISTEMA

| Servicio | Estado |
|----------|--------|
| docker | running |
| cloudflared | running |
| ssh | running |
| tailscaled | running |

---

## DOCKER - RESUMEN

- **Contenedores corriendo**: 57
- **Redes Docker**: 26
- **Imagenes**: 59
- **Volumenes**: 86

---

## CONTENEDORES POR PROYECTO (57 activos)

### FROGIO - Gestion Municipal (8 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| frogioweb | 3025 | frogioweb-frogioweb | 33MB |
| frogio-backend | 3110 | frogio-backend | 45MB |
| frogio-web-admin | 3111 | frogio-web-admin | 36MB |
| frogio-postgres | 5432 | postgres:16-alpine | 21MB |
| frogio-redis | 6379 | redis:7-alpine | 5MB |
| frogio-minio | 9100/9101 | minio/minio | 80MB |
| frogio-uptime | 3004 | louislam/uptime-kuma:1 | 99MB |
| frogio-ntfy | 8080 | binwiederhier/ntfy | 20MB |

**Subdominios:**
- frogio.drozast.xyz → :3025
- api-frogio.drozast.xyz → :3110
- admin-frogio.drozast.xyz → :3111
- minio-frogio.drozast.xyz → :9100

---

### Casa Infante - Inmobiliaria (5 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| casainfante-frontend | 3040 | casa-infante-frontend | 45MB |
| casainfante-backend | 3041 | casa-infante-backend | 45MB |
| casainfante-postgres | 5441 | postgres:16-alpine | 34MB |
| casainfante-redis | 6381 | redis:7-alpine | 5MB |
| casainfante-minio | 9020/9021 | minio/minio | 77MB |

**Subdominios:**
- casainfante.drozast.xyz → :3040
- apicasainfante.drozast.xyz → :3041

---

### House Advisor - Real Estate (7 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| houseadvisor-web | 3061 | house-advisor-web | 33MB |
| houseadvisor-api | 3060 | house-advisor-api | 38MB |
| houseadvisor-evolution | 8085 | atendai/evolution-api:v2.2.3 | 89MB |
| houseadvisor-postgres | 5460 | postgres:16-alpine | 24MB |
| houseadvisor-redis | 6390 | redis:7-alpine | 5MB |
| houseadvisor-minio | 9062/9063 | minio/minio | 96MB |
| cloudflared-houseadvisor | - | cloudflare/cloudflared | 21MB |

**Dominios (cuenta CF separada):**
- houseadvisor.cl → :3061
- app.houseadvisor.cl → :3061
- api.houseadvisor.cl → :3060

---

### BarberBook - Reservas (5 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| barberbook-frontend | 3030 | barberbook-pro-frontend | 77MB |
| barberbook-api | 4000 | barberbook-pro-api | 74MB |
| barberbook-dashboard | 3031 | barberbook-pro-dashboard | 84MB |
| barberbook-postgres | 5450 | postgres:16-alpine | 16MB |
| barberbook-redis | 6380 | redis:7-alpine | 4MB |

---

### Reclamaya - Reclamos Ciudadanos (4 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| reclamaya-web | 3021 | reclamaya-web | 45MB |
| reclamaya-api | 3020 | reclamaya-api | 48MB |
| reclamaya-whatsapp | 3026 | reclamaya-whatsapp | 1.9GB |
| reclamaya-postgres | 5440 | postgres:16-alpine | 24MB |

**Dominios:**
- reclamaya.cl → :3021
- api.reclamaya.cl → :3020

---

### AgendAuto - Reservas Automotriz (6 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| agendauto-app | 3008 | agendauto-web-agendauto-app | 74MB |
| agendauto-api | - (interno) | agendauto-api | 60MB |
| agendauto-postgres | 5435 | postgres:16-alpine | 21MB |
| agendauto-redis | 6382 | redis:7-alpine | 5MB |
| agendauto-minio | 9000/9001 | minio/minio | 118MB |
| cloudflared-agendauto | - | cloudflare/cloudflared | 20MB |

**Dominios:**
- api.agendauto.cl → :3007

---

### Supertools (4 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| supertools-app | 3050 | supertools-app | 71MB |
| supertools-python | 5100 | supertools-python-api | 224MB |
| supertools-redis | - (interno) | redis:alpine | 6MB |
| supertools-cleanup | - | alpine | 1MB |

**Subdominio:** tools.drozast.xyz → :3050

---

### Mapas Self-Hosted Chile (3 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| maps-tileserver | 8082 | maptiler/tileserver-gl | 160MB |
| maps-nominatim | 8081 | mediagis/nominatim:4.4 | 493MB |
| maps-osrm | 5000 | osrm/osrm-backend | 356MB |

**Subdominios:**
- maps.drozast.xyz → :8082
- geo.drozast.xyz → :8081
- routing.drozast.xyz → :5000

---

### Cloud & Media (3 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| nextcloud | 10081 | nextcloud:latest | 214MB |
| nextcloud-db | - (interno) | postgres:16-alpine | 40MB |
| metube | 8084 | ghcr.io/alexta69/metube | 59MB |

**Subdominios:**
- cloud.drozast.xyz → :10081
- metube.drozast.xyz → :8084

---

### Trading Bot (3 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| redis-btcbot | 6391 | redis:alpine | 2.2GB |
| btctrading-postgres | 5470 | postgres:16-alpine | 30MB |

**Subdominio:** btc.drozast.xyz → :8089 (Mac local)

---

### DevOps & Automatizacion (3 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| gitea | 3003, 2222 | gitea/gitea | 137MB |
| n8n | 5678 | n8nio/n8n | 291MB |

**Subdominio:** n8n.drozast.xyz → :5678

---

### WMS Troya (2 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| wms-app | 3002 | wms-troya-app | 93MB |
| wms-postgres | 5434 | postgres:15-alpine | 25MB |

**Subdominio:** wms.drozast.xyz → :3002

---

### TodoApp (3 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| todoapp-backend | 3006 | todoapp-backend-backend | 31MB |
| todoapp-mysql | - (interno) | mysql:8.4 | 191MB |
| todoapp-cloudflared | - | cloudflare/cloudflared | 22MB |

**Subdominio:** todoapp.drozast.xyz → :3006

---

### Miseenplace Restaurant (1 contenedor)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| miseenplace-restaurant | 5174 | miseenplace-restaurant | 56MB |

---

### Infraestructura (2 contenedores)

| Container | Puerto Host | Imagen | RAM |
|-----------|-------------|--------|-----|
| drozast-dashboard | 8070 | nginx:alpine | 21MB |
| server-stats | 8095 | php:8.2-cli | 24MB |

**Subdominios:**
- drozast.xyz → :8070
- stats.drozast.xyz → :8095

---

## CLOUDFLARE TUNNELS

### Tunnel Principal (drozast)
```yaml
tunnel: 804e80c5-881c-4ad0-963a-42549223c4f6
credentials-file: /home/nonroot/.cloudflared/804e80c5-881c-4ad0-963a-42549223c4f6.json
```

**Rutas configuradas:**
| Hostname | Servicio |
|----------|----------|
| drozast.xyz | :8070 |
| stats.drozast.xyz | :8095 |
| ssh.drozast.xyz | ssh://:22 |
| frogio.drozast.xyz | :3025 |
| api-frogio.drozast.xyz | :3110 |
| admin-frogio.drozast.xyz | :3111 |
| minio-frogio.drozast.xyz | :9100 |
| casainfante.drozast.xyz | :3040 |
| apicasainfante.drozast.xyz | :3041 |
| maps.drozast.xyz | :8082 |
| geo.drozast.xyz | :8081 |
| routing.drozast.xyz | :5000 |
| ai.drozast.xyz | :3201 |
| api-ai.drozast.xyz | :3202 |
| llm.drozast.xyz | :3203 |
| dashboard-ai.drozast.xyz | :3206 |
| n8n.drozast.xyz | :5678 |
| metube.drozast.xyz | :8084 |
| ntfy.drozast.xyz | :8080 |
| evolution.drozast.xyz | :8086 |
| api.agendauto.cl | :3007 |
| btc.drozast.xyz | :8089 |

### Tunnel House Advisor (cuenta separada)
```yaml
tunnel: 1346d8d5-19d0-4238-951e-354e5469e42e
container: cloudflared-houseadvisor
```

### Tunnel AgendAuto
```yaml
container: cloudflared-agendauto
```

### Tunnel TodoApp
```yaml
container: todoapp-cloudflared
```

---

## MAPA DE PUERTOS

### Puertos 3000-3999 (Apps Web)
| Puerto | Container | Proyecto |
|--------|-----------|----------|
| 3002 | wms-app | WMS Troya |
| 3003 | gitea | DevOps |
| 3004 | frogio-uptime | FROGIO |
| 3006 | todoapp-backend | TodoApp |
| 3008 | agendauto-app | AgendAuto |
| 3020 | reclamaya-api | Reclamaya |
| 3021 | reclamaya-web | Reclamaya |
| 3025 | frogioweb | FROGIO |
| 3026 | reclamaya-whatsapp | Reclamaya |
| 3030 | barberbook-frontend | BarberBook |
| 3031 | barberbook-dashboard | BarberBook |
| 3040 | casainfante-frontend | Casa Infante |
| 3041 | casainfante-backend | Casa Infante |
| 3050 | supertools-app | Supertools |
| 3060 | houseadvisor-api | House Advisor |
| 3061 | houseadvisor-web | House Advisor |
| 3110 | frogio-backend | FROGIO |
| 3111 | frogio-web-admin | FROGIO |

### Puertos 4000-5999 (APIs/Services)
| Puerto | Container | Proyecto |
|--------|-----------|----------|
| 4000 | barberbook-api | BarberBook |
| 5000 | maps-osrm | Mapas |
| 5100 | supertools-python | Supertools |
| 5174 | miseenplace-restaurant | Miseenplace |
| 5432 | frogio-postgres | FROGIO |
| 5434 | wms-postgres | WMS |
| 5435 | agendauto-postgres | AgendAuto |
| 5440 | reclamaya-postgres | Reclamaya |
| 5441 | casainfante-postgres | Casa Infante |
| 5450 | barberbook-postgres | BarberBook |
| 5460 | houseadvisor-postgres | House Advisor |
| 5470 | btctrading-postgres | Trading |
| 5678 | n8n | DevOps |

### Puertos 6000-6999 (Redis)
| Puerto | Container | Proyecto |
|--------|-----------|----------|
| 6379 | frogio-redis | FROGIO |
| 6380 | barberbook-redis | BarberBook |
| 6381 | casainfante-redis | Casa Infante |
| 6382 | agendauto-redis | AgendAuto |
| 6390 | houseadvisor-redis | House Advisor |
| 6391 | redis-btcbot | Trading |

### Puertos 8000-8999 (HTTP Services)
| Puerto | Container | Proyecto |
|--------|-----------|----------|
| 8070 | drozast-dashboard | Infra |
| 8080 | frogio-ntfy | FROGIO |
| 8081 | maps-nominatim | Mapas |
| 8082 | maps-tileserver | Mapas |
| 8084 | metube | Media |
| 8085 | houseadvisor-evolution | House Advisor |
| 8095 | server-stats | Infra |

### Puertos 9000-9999 (MinIO)
| Puerto | Container | Proyecto |
|--------|-----------|----------|
| 9000/9001 | agendauto-minio | AgendAuto |
| 9020/9021 | casainfante-minio | Casa Infante |
| 9062/9063 | houseadvisor-minio | House Advisor |
| 9100/9101 | frogio-minio | FROGIO |

### Puertos 10000+ (Otros)
| Puerto | Container | Proyecto |
|--------|-----------|----------|
| 10081 | nextcloud | Cloud |
| 2222 | gitea (SSH) | DevOps |

---

## RUTAS DE PROYECTOS

```bash
# Dashboard
/home/drozast/dashboard/
/home/drozast/drozastdashboard/

# FROGIO
/home/drozast/frogio/
/home/drozast/frogio-backend/
/home/drozast/frogio-web-admin/
/home/drozast/frogioweb/

# Aplicaciones
/home/drozast/house-advisor/
/home/drozast/casa-infante/
/home/drozast/reclamaya/
/home/drozast/agendauto-api/
/home/drozast/supertools/
/home/drozast/todoapp-backend/

# Trading
/home/drozast/tradebot/
/home/drozast/btc-trading-bot/

# Infraestructura
/home/drozast/maps/
/home/drozast/nextcloud/
/home/drozast/gitea/
/home/drozast/n8n-tiktok/
/home/drozast/ai-platform/
/home/drozast/metube/

# Datos
/mnt/nextcloud-data/

# Cloudflare
~/.cloudflared/config.yml
```

---

## REDES DOCKER

| Red | Driver | Uso |
|-----|--------|-----|
| agendauto_agendauto-network | bridge | AgendAuto |
| barberbook-pro_barberbook-network | bridge | BarberBook |
| casa-infante_casainfante-network | bridge | Casa Infante |
| frogio_frogio_network | bridge | FROGIO |
| frogioweb_frogio-network | bridge | FROGIO Web |
| houseadvisor-network | bridge | House Advisor |
| maps_default | bridge | Mapas |
| nextcloud_nextcloud-net | bridge | Nextcloud |
| reclamaya-network | bridge | Reclamaya |
| supertools_supertools-network | bridge | Supertools |
| todoapp-network | bridge | TodoApp |
| tradebot_btc-network | bridge | Trading |
| wms-troya_wms-network | bridge | WMS |

---

## CREDENCIALES CLOUDFLARE

### Cuenta Principal (drozast)
```bash
ACCOUNT_ID="8f1d429e9debfd3bf3e97af51e9b3af0"
API_TOKEN="1lWRufiPWx68uGE5jpIRc3auPGOyzFnJ9WIXV2G7"
TUNNEL_ID="804e80c5-881c-4ad0-963a-42549223c4f6"
```

### Cuenta House Advisor
```bash
EMAIL="houseadvisor999@gmail.com"
ACCOUNT_ID="3f96fb92ff007a023109a126301cd0a1"
API_TOKEN="1ugqxEh4CWmyVCwC9dUw6NBSplY6UGE8ffNF5xHF"
TUNNEL_ID="1346d8d5-19d0-4238-951e-354e5469e42e"
```

---

## USO DE RECURSOS ACTUAL

### Top consumidores de RAM
| Container | RAM |
|-----------|-----|
| redis-btcbot | 2.2GB |
| reclamaya-whatsapp | 1.9GB |
| maps-nominatim | 493MB |
| maps-osrm | 356MB |
| n8n | 291MB |
| supertools-python | 224MB |
| nextcloud | 214MB |
| todoapp-mysql | 191MB |
| maps-tileserver | 160MB |
| gitea | 137MB |

### Resumen
- **RAM Total**: 31GB
- **RAM Usada**: 23GB (76%)
- **RAM Disponible**: 7.2GB
- **Swap Usado**: 3.6GB/4GB

---

## NOTAS TECNICAS

### Backup RAID
- Estado: [UU] - Ambos discos funcionando
- Tipo: RAID1 (mirror)
- Cache: bcache habilitado

### Interfaces de Red
- enp6s0: 192.168.31.145 (Principal)
- enp7s0: 192.168.1.7 (Secundaria)
- tailscale0: 100.99.166.80 (VPN)

### Servicios Inactivos (sin contenedores corriendo)
- AI Platform (Ollama, WebUI, LiteLLM)
- Mailpit
- Shlink
- Navidrome
- Kavita

---

## CHANGELOG

| Fecha | Cambio |
|-------|--------|
| 2026-02-27 | Documento regenerado con datos reales del servidor |
