#!/usr/bin/env python3
"""
Sube el sistema al servidor tecnoweb por FTP (el servidor no da acceso SSH).

Estructura en el servidor  (/home/grupo08sa = https://www.tecnoweb.org.bo/inf513/grupo08sa):
    proyecto2/            -> carpeta pública: index.php, .htaccess, build/, favicon
    proyecto2/_app/       -> código Laravel + vendor + .env  (bloqueado con .htaccess: Require all denied)

Uso (desde la carpeta del proyecto, después de "bash scripts/empaquetar.sh"):
    FTP_PASSWORD='...' python scripts/subir_ftp.py            sube a proyecto2_nuevo y la intercambia con proyecto2
    FTP_PASSWORD='...' python scripts/subir_ftp.py --solo-codigo   igual, pero reutiliza el .env que ya está en el servidor

La versión anterior queda como proyecto2_respaldo_<fecha> (se puede volver a ella renombrando).
"""
import ftplib
import io
import os
import sys
import tarfile
import time

HOST = os.environ.get('FTP_HOST', 'www.tecnoweb.org.bo')
USUARIO = os.environ.get('FTP_USUARIO', 'grupo08sa')
CLAVE = os.environ.get('FTP_PASSWORD')
DESTINO = os.environ.get('FTP_DESTINO', 'proyecto2')
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
        salida.append(('_app/.env', open(ENV_SERVIDOR, 'rb').read()))
    return salida


def main():
    if not CLAVE:
        sys.exit('Defina la contraseña: FTP_PASSWORD=... python scripts/subir_ftp.py')
    solo_codigo = '--solo-codigo' in sys.argv
    archivos = archivos_a_subir(solo_codigo)
    nuevo = DESTINO + '_nuevo'
    ftp = conectar()
    print(f'Conectado a {HOST}. Subiendo {len(archivos)} archivos a /{nuevo} …')

    creadas = set()
    inicio = time.time()
    for i, (ruta, datos) in enumerate(archivos, 1):
        remoto = f'{nuevo}/{ruta}'
        mkdir(ftp, os.path.dirname(remoto), creadas)
        for intento in range(3):
            try:
                ftp.storbinary(f'STOR /{remoto}', io.BytesIO(datos))
                break
            except (ftplib.error_temp, EOFError, OSError, ftplib.error_reply):
                time.sleep(2)
                ftp = conectar()
        if i % 250 == 0 or i == len(archivos):
            print(f'  {i}/{len(archivos)}  ({time.time() - inicio:.0f} s)')

    if solo_codigo and existe(ftp, f'{DESTINO}/_app'):
        # conservar el .env actual del servidor
        buf = io.BytesIO()
        ftp.retrbinary(f'RETR /{DESTINO}/_app/.env', buf.write)
        ftp.storbinary(f'STOR /{nuevo}/_app/.env', io.BytesIO(buf.getvalue()))

    # Carpetas que PHP (usuario apache) debe poder escribir
    for d in ['storage', 'storage/app', 'storage/app/respaldos', 'storage/framework', 'storage/framework/cache', 'storage/framework/cache/data',
              'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache']:
        mkdir(ftp, f'{nuevo}/_app/{d}', creadas)
        chmod(ftp, f'{nuevo}/_app/{d}', '777')

    # Intercambio: la versión actual pasa a respaldo y la nueva queda publicada
    if existe(ftp, DESTINO):
        respaldo = f'{DESTINO}_respaldo_{time.strftime("%Y%m%d_%H%M%S")}'
        ftp.rename('/' + DESTINO, '/' + respaldo)
        print(f'Versión anterior guardada como /{respaldo}')
    ftp.rename('/' + nuevo, '/' + DESTINO)
    ftp.quit()
    print(f'✔ Publicado en https://www.tecnoweb.org.bo/inf513/grupo08sa/{DESTINO}  ({time.time() - inicio:.0f} s)')


if __name__ == '__main__':
    main()
