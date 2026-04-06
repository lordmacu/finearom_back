#!/bin/bash

echo "🚀 Iniciando deploy de Laravel..."
echo ""

# Colores para output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 1. Limpiar caches
echo -e "${BLUE}📦 Limpiando caches...${NC}"
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear
echo -e "${GREEN}✓ Caches limpiados${NC}"
echo ""

# 2. Crear directorios necesarios
echo -e "${BLUE}📁 Creando directorios necesarios...${NC}"
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
mkdir -p bootstrap/cache
echo -e "${GREEN}✓ Directorios creados${NC}"
echo ""

# 3. Configurar permisos
echo -e "${BLUE}🔐 Configurando permisos...${NC}"
sudo chown -R bitnami:daemon storage
sudo chown -R bitnami:daemon bootstrap/cache
sudo chmod -R 775 storage
sudo chmod -R 775 bootstrap/cache
echo -e "${GREEN}✓ Permisos configurados${NC}"
echo ""

# 4. Optimizar para producción
echo -e "${BLUE}⚡ Optimizando para producción...${NC}"
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo -e "${GREEN}✓ Optimización completada${NC}"
echo ""

# 5. Migrar base de datos
echo -e "${BLUE}🗄️  Ejecutando migraciones...${NC}"
php artisan migrate --force
echo -e "${GREEN}✓ Migraciones ejecutadas${NC}"
echo ""

echo -e "${GREEN}✅ Deploy completado exitosamente!${NC}"
echo ""
echo "Puedes ejecutar estos comandos adicionales si es necesario:"
echo "  - php artisan db:seed --class=EmailTemplateSeeder  (para sembrar templates)"
echo "  - sudo /opt/bitnami/ctlscript.sh restart apache     (para reiniciar Apache)"
