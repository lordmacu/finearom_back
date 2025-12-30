#!/bin/bash

echo "🔄 Reiniciando aplicación..."
echo ""

# Limpiar y optimizar
./deploy.sh

# Reiniciar Apache
echo "🔄 Reiniciando Apache..."
sudo /opt/bitnami/ctlscript.sh restart apache

echo ""
echo "✅ Aplicación reiniciada completamente!"
