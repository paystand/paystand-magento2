# Limpiar Cache de Magento - Error de Clase No Encontrada

## Problema
Magento está buscando clases que ya no existen (`TestPaystandConfig`, `TestScopeConfig`) porque están en el cache de compilación.

## Solución

### Opción 1: Limpiar cache y regenerar (Recomendado)

**Dentro del contenedor Docker:**

```bash
# 1. Limpiar cache
php bin/magento cache:clean
php bin/magento cache:flush

# 2. Limpiar código generado
rm -rf generated/code/*
rm -rf generated/metadata/*

# 3. Regenerar código
php bin/magento setup:di:compile
```

### Opción 2: Limpiar todo y recompilar

```bash
# Limpiar todo
php bin/magento cache:clean
php bin/magento cache:flush
rm -rf generated/*
rm -rf var/cache/*
rm -rf var/page_cache/*

# Recompilar
php bin/magento setup:di:compile
```

### Opción 3: Si estás en Docker desde el host

```bash
# Reemplaza [container_name] con el nombre de tu contenedor
docker exec -it [container_name] php bin/magento cache:clean
docker exec -it [container_name] php bin/magento cache:flush
docker exec -it [container_name] rm -rf generated/code/*
docker exec -it [container_name] rm -rf generated/metadata/*
docker exec -it [container_name] php bin/magento setup:di:compile
```

## Verificar que funcionó

Después de limpiar, verifica que no haya errores:

```bash
php bin/magento setup:di:compile
```

Si todo está bien, deberías ver:
```
Compilation was started.
...
Generated code and dependency injection configuration successfully.
```

## Nota

El archivo `etc/di.xml` ya fue limpiado y ya no tiene referencias a las clases eliminadas. Solo necesitas limpiar el cache de Magento.


