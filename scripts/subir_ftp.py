#!/usr/bin/env python3
"""
Sube el sistema al servidor tecnoweb por FTP (el servidor no da acceso SSH).

Estructura en el servidor  (/home/grupo08sa = https://www.tecnoweb.org.bo/inf513/grupo08sa):
    proyecto2/            -> carpeta pública: index.php, .htaccess, build/, favicon
    proyecto2/_app/       -> código Laravel + vendor + .env  (bloqueado con .htaccess: Require all denied)

Uso (desde la carpeta del proyecto, después de "bash scripts/empaquetar.sh"):
    FTP_PASSWORD='...' python scripts/subir_ftp.py            sube un .zip, el servidor lo descomprime en
                                                              proyecto2_nuevo_<fecha> y se intercambia con proyecto2
    FTP_PASSWORD='...' python scripts/subir_ftp.py --solo-codigo   igual, pero reutiliza el .env que ya está en el servidor
    FTP_PASSWORD='...' python scripts/subir_ftp.py --reparar       RÁPIDO: compara con el paquete y solo vuelve a subir
                                                                   lo que falta o fue modificado (para la defensa)

La versión anterior queda como proyecto2_respaldo_<fecha> (se puede volver a ella renombrando).
"""
import ftplib
import io
import os
import sys
import tarfile
import time

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")  # consola de Windows

HOST = os.environ.get('FTP_HOST', 'www.tecnoweb.org.bo')
USUARIO = os.environ.get('FTP_USUARIO', 'grupo08sa')
CLAVE = os.environ.get('FTP_PASSWORD')
DESTINO = os.environ.get('FTP_DESTINO', 'proyecto2')
URL_BASE = os.environ.get('URL_BASE', 'https://www.tecnoweb.org.bo/inf513/grupo08sa')
PAQUETE = 'dist/proyecto2_app.tar.gz'
ENV_SERVIDOR = '.env.servidor'

HTACCESS_APP = 'Require all denied\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n'
INDEX = open('deploy/index.produccion.php', encoding='utf-8').read().replace("'__APP_DIR__'", "__DIR__.'/_app'")


def conectar():
    ftp = ftplib.FTP(HOST, timeout=60)
    ftp.login(USUARIO, CLAVE)
    ftp.set_pasv(True)
    return ftp


def existe(ftp, ruta):
    try:
        ftp.cwd('/' + ruta)
        ftp.cwd('/')
        return True
    except ftplib.error_perm:
        return False


def mkdir(ftp, ruta, creadas):
    partes = ruta.strip('/').split('/')
    for i in range(1, len(partes) + 1):
        p = '/'.join(partes[:i])
        if p not in creadas:
            try:
                ftp.mkd('/' + p)
            except ftplib.error_perm:
                pass
            creadas.add(p)


def chmod(ftp, ruta, modo):
    try:
        ftp.sendcmd(f'SITE CHMOD {modo} /{ruta}')
    except ftplib.error_perm:
        pass


def archivos_a_subir(solo_codigo):
    """(ruta_remota_relativa, bytes) de todo lo que se sube"""
    salida = []
    with tarfile.open(PAQUETE, 'r:gz') as tar:
        for m in tar.getmembers():
            if not m.isfile():
                continue
            nombre = m.name[2:] if m.name.startswith('./') else m.name
            datos = tar.extractfile(m).read()
            if nombre.startswith('public/'):
                publico = nombre[len('public/'):]
                if publico == 'index.php':
                    continue
                salida.append((publico, datos))          # parte pública
            else:
                salida.append(('_app/' + nombre, datos))  # código protegido
    salida.append(('index.php', INDEX.encode()))
    salida.append(('_app/.htaccess', HTACCESS_APP.encode()))
    if not solo_codigo:
        # Dentro del servidor la BD se alcanza por 127.0.0.1 (por el nombre público la conexión es rechazada)
        env = open(ENV_SERVIDOR, encoding='utf-8').read()
        host = os.environ.get('DB_HOST_EN_SERVIDOR', '127.0.0.1')
        env = '\n'.join(f'DB_HOST={host}' if l.startswith('DB_HOST=') else l for l in env.splitlines()) + '\n'
        salida.append(('_app/.env', env.encode()))
    return salida


def reparar():
    """Compara el servidor con el paquete (tamaño de cada archivo) y repone solo lo dañado."""
    archivos = archivos_a_subir(solo_codigo=False)
    ftp = conectar()
    inicio = time.time()
    vendor_ok = all(tamano(ftp, f'{DESTINO}/_app/vendor/{f}') is not None for f in ['autoload.php', 'composer/autoload_real.php', 'laravel/framework/composer.json'])
    if not vendor_ok:
        print('vendor/ dañado: se sube completo.')
    repuestos = 0
    creadas = set()
    for ruta, datos in archivos:
        if ruta.startswith('_app/vendor/') and vendor_ok:
            continue
        if ruta == '_app/.env' and tamano(ftp, f'{DESTINO}/_app/.env') is not None:
            continue  # no pisar un .env que existe (puede tener cambios del servidor)
        remoto = f'{DESTINO}/{ruta}'
        if tamano(ftp, remoto) != len(datos):
            mkdir(ftp, os.path.dirname(remoto), creadas)
            ftp.storbinary(f'STOR /{remoto}', io.BytesIO(datos))
            repuestos += 1
            print('  repuesto:', ruta)
    for d in ['storage', 'storage/app', 'storage/app/respaldos', 'storage/framework', 'storage/framework/cache', 'storage/framework/cache/data',
              'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache']:
        mkdir(ftp, f'{DESTINO}/_app/{d}', creadas)
        chmod(ftp, f'{DESTINO}/_app/{d}', '777')
    ftp.quit()
    print(f'✔ Reparación terminada: {repuestos} archivo(s) repuesto(s) en {time.time() - inicio:.0f} s')


def tamano(ftp, ruta):
    try:
        ftp.voidcmd('TYPE I')
        return ftp.size('/' + ruta)
    except ftplib.error_perm:
        return None


# PHP temporal que descomprime el paquete en el servidor (subir 6000 archivos uno por uno por FTP tarda >1 hora)
DESEMPAQUETADOR = r'''<?php
// Desempaquetador temporal: descomprime _paquete.zip aquí y se borra solo.
if (!hash_equals('__TOKEN__', (string) ($_GET['t'] ?? ''))) { http_response_code(403); exit('denegado'); }
set_time_limit(600);
$zip = new ZipArchive();
if ($zip->open(__DIR__ . '/_paquete.zip') !== true) { http_response_code(500); exit('ERROR: no se pudo abrir el zip'); }
$ok = $zip->extractTo(__DIR__);
$n = $zip->numFiles;
$zip->close();
// Permisos: PHP (apache) y el usuario FTP deben poder escribir (storage, logs, futuras reparaciones)
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) { @chmod($f->getPathname(), $f->isDir() ? 0777 : 0666); }
@unlink(__DIR__ . '/_paquete.zip');
@unlink(__FILE__);
echo $ok ? "OK $n" : 'ERROR al descomprimir';
'''


def main():
    if not CLAVE:
        sys.exit('Defina la contraseña: FTP_PASSWORD=... python scripts/subir_ftp.py')
    if '--reparar' in sys.argv:
        return reparar()
    import secrets
    import urllib.request
    import zipfile

    solo_codigo = '--solo-codigo' in sys.argv
    inicio = time.time()
    archivos = archivos_a_subir(solo_codigo)
    ftp = conectar()
    if solo_codigo and existe(ftp, f'{DESTINO}/_app'):  # conservar el .env del servidor
        buf = io.BytesIO()
        ftp.retrbinary(f'RETR /{DESTINO}/_app/.env', buf.write)
        archivos.append(('_app/.env', buf.getvalue()))

    paquete = io.BytesIO()
    with zipfile.ZipFile(paquete, 'w', zipfile.ZIP_DEFLATED) as z:
        for ruta, datos in archivos:
            z.writestr(ruta, datos)
        for d in ['storage/app/respaldos', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache']:
            z.writestr(f'_app/{d}/', b'')
    print(f'Paquete: {len(archivos)} archivos, {paquete.tell() / 1048576:.1f} MB')

    nuevo = f'{DESTINO}_nuevo_{time.strftime("%Y%m%d%H%M%S")}'
    token = secrets.token_hex(24)
    ftp.mkd('/' + nuevo)
    chmod(ftp, nuevo, '777')  # para que PHP (apache) pueda descomprimir dentro
    ftp.storbinary(f'STOR /{nuevo}/_paquete.zip', io.BytesIO(paquete.getvalue()))
    ftp.storbinary(f'STOR /{nuevo}/_desplegar.php', io.BytesIO(DESEMPAQUETADOR.replace('__TOKEN__', token).encode()))
    print(f'Subido ({time.time() - inicio:.0f} s). Descomprimiendo en el servidor…')

    url = f'{URL_BASE}/{nuevo}/_desplegar.php?t={token}'
    with urllib.request.urlopen(url, timeout=600) as r:
        respuesta = r.read().decode(errors='replace')
    if not respuesta.startswith('OK'):
        sys.exit(f'✘ El servidor respondió: {respuesta[:300]}  (la versión publicada NO se tocó)')
    print(f'Descomprimidos {respuesta.split()[1]} archivos.')

    # Intercambio: la versión actual pasa a respaldo y la nueva queda publicada (segundos de corte)
    ftp = conectar()
    if existe(ftp, DESTINO):
        respaldo = f'{DESTINO}_respaldo_{time.strftime("%Y%m%d_%H%M%S")}'
        ftp.rename('/' + DESTINO, '/' + respaldo)
        print(f'Versión anterior guardada como /{respaldo}')
    ftp.rename('/' + nuevo, '/' + DESTINO)
    ftp.quit()
    print(f'✔ Publicado en {URL_BASE}/{DESTINO}  ({time.time() - inicio:.0f} s)')


if __name__ == '__main__':
    main()
