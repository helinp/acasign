#!/usr/bin/env sh
set -eu
# Organisation inscrite dans le certificat : CERT_ORGANIZATION="Nom de l'établissement" ./scripts/generate-signing-certificate.sh
ORGANIZATION="${CERT_ORGANIZATION:-Établissement}"
mkdir -p secrets
umask 077
openssl req -x509 -newkey rsa:4096 -sha256 -days 1825 -nodes \
  -keyout secrets/pdf-signing.key \
  -out secrets/pdf-signing.crt \
  -subj "/C=BE/O=${ORGANIZATION}/CN=AcaSign - cachet interne"
chmod 600 secrets/pdf-signing.key
echo "Certificat interne créé dans secrets/. Conservez ces deux fichiers dans votre sauvegarde sécurisée."
