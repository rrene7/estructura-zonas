# Verificar base local

Si el comando de creacion devuelve `estructura_zonas_test`, la base fue creada.

## Comando de verificacion

```bash
/c/xampp/mysql/bin/mysql.exe -u root -e "SHOW DATABASES LIKE 'estructura_zonas_test';"
```

## Ver tablas

```bash
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test -e "SHOW TABLES;"
```

## Entrar a MySQL

```bash
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test
```

Dentro de MySQL:

```sql
SHOW TABLES;
EXIT;
```

## Recomendacion

Desde la raiz del proyecto usar:

```bash
cd /c/xampp/htdocs/estructura-zonas
bash scripts/setup_moi_xampp.sh
```

Luego volver a verificar las tablas.
