#!/usr/bin/env sh
set -eu
mkdir -p secrets
umask 077
openssl req -x509 -newkey rsa:4096 -sha256 -days 1825 -nodes \
  -keyout secrets/pdf-signing.key \
  -out secrets/pdf-signing.crt \
  -subj "/C=BE/O=AC'Arts/CN=Visa - cachet interne"
chmod 600 secrets/pdf-signing.key
echo "Certificat interne créé dans secrets/. Conservez ces deux fichiers dans votre sauvegarde sécurisée."
