# Visa

Application interne de signature simple de documents PDF. Elle utilise exclusivement Microsoft Entra ID pour les utilisateurs, appose une image chiffrée et un QR de vérification pour chaque signataire, puis scelle le PDF final avec un certificat interne.

> Visa fournit une preuve de validation interne. Ce n’est pas une signature avancée ou qualifiée eIDAS.

## Architecture

- Laravel 13 / PHP 8.4, rendu serveur Blade et Bootstrap 5
- PostgreSQL 17, file de tâches Laravel en base
- PDF.js pour le placement visuel
- FPDI/TCPDF pour l’aplatissement, l’apposition et le scellement
- QR opaque et contrôle SHA-256
- journal append-only chaîné par empreintes
- image de signature chiffrée avec `APP_KEY`
- quatre conteneurs applicatifs issus de la même image : web, migration, worker et scheduler

## Installation locale

```sh
cp .env.example .env
composer install
npm ci
npm run build
php artisan key:generate
php artisan migrate
./scripts/generate-signing-certificate.sh
php artisan serve
```

Pour un développement local en SQLite, remplacez `DB_CONNECTION` par `sqlite` et créez `database/database.sqlite`.

## Microsoft Entra

1. Créer une inscription d’application mono-tenant.
2. Ajouter l’URI de redirection `https://<hôte>/auth/entra/callback` dans la plateforme Web.
3. Créer un secret client, ou injecter un secret géré par Portainer.
4. Renseigner `ENTRA_TENANT_ID`, `ENTRA_CLIENT_ID`, `ENTRA_CLIENT_SECRET` et `ENTRA_ALLOWED_DOMAINS`.
5. Renseigner `BOOTSTRAP_ADMIN_EMAIL` avec le compte du premier administrateur.
6. Dans Conditional Access, exiger le MFA pour cette application. `SESSION_LIFETIME=480` limite la session applicative à huit heures.

L’application emploie Authorization Code + PKCE, valide la signature du jeton avec le JWKS Microsoft, ainsi que `aud`, `tid`, `nonce`, expiration et domaine. Les invités `#EXT#` et les identifiants contenant `.ELEVE` sont refusés.

## Signature eID (carte d’identité belge)

Un type de document marqué « Signature eID requise » (Admin → Types de documents) se signe avec la carte eID :

1. le poste du signataire a un lecteur, le logiciel eID belge et **Web eID** (extension + application, https://web-eid.eu/) ; l’aide de *Mon profil* vérifie l’installation ;
2. l’application obtient le certificat de signature de la carte, DSS (`dss`, conteneur interne) calcule les données à signer, la carte les signe après saisie du code PIN ;
3. DSS intègre la signature (PAdES, révision incrémentale) ; le PDF n’est plus réécrit ensuite, l’apparence visible (image et mentions) est dessinée par DSS.

En local, Web eID refuse `http://localhost` : utilisez `https://localhost:8443` avec un certificat mkcert (une fois) :

```bash
brew install mkcert nss && mkcert -install
mkdir -p docker/dev-certs && mkcert -cert-file docker/dev-certs/localhost.pem -key-file docker/dev-certs/localhost-key.pem localhost 127.0.0.1
chmod 644 docker/dev-certs/*.pem
```

puis `APP_URL=https://localhost:8443` dans `.env` et `docker compose up -d --build`.

Limites actuelles : pas de champs à compléter sur ces documents ; niveau `PAdES_BASELINE_LT` par défaut (`DSS_SIGNATURE_LEVEL`) : horodatage via `TSA_URL` (conteneur `dss`) et éléments de révocation intégrés. Dans Acrobat, activer la liste de confiance européenne (Préférences → Gestionnaire de confiance → EUTL) pour que l’identité soit reconnue. Web eID exige HTTPS (sauf `localhost`).

## Déploiement Portainer / Traefik

Pour Portainer avec Docker Standalone, pousser le code dans un dépôt Git privé puis créer une stack depuis ce dépôt avec `docker-compose.portainer.yml`. Charger `.env.example` dans les variables de la stack : Portainer les fournit aux services via `stack.env`. Ne jamais versionner `.env`, `stack.env` ni le contenu de `secrets/`.

Préparer une fois le serveur Docker :

```sh
sudo install -d -o 70 -g 70 /srv/docker/signature/postgres
sudo install -d -o 33 -g 33 /srv/docker/signature/data /srv/docker/signature/logs
sudo install -d -o 33 -g 33 -m 750 /srv/docker/signature/secrets
sudo openssl req -x509 -newkey rsa:4096 -sha256 -days 1825 -nodes \
  -keyout /srv/docker/signature/secrets/pdf-signing.key \
  -out /srv/docker/signature/secrets/pdf-signing.crt \
  -subj "/C=BE/O=AC'Arts/CN=Visa - cachet interne"
sudo chown 33:33 /srv/docker/signature/secrets/pdf-signing.key /srv/docker/signature/secrets/pdf-signing.crt
sudo chmod 400 /srv/docker/signature/secrets/pdf-signing.key
sudo chmod 444 /srv/docker/signature/secrets/pdf-signing.crt
docker network inspect proxy >/dev/null 2>&1 || docker network create proxy
```

Dans Portainer : **Stacks → Add stack → Git repository**, renseigner le dépôt et la branche, puis indiquer `docker-compose.portainer.yml` comme chemin Compose. Charger `.env.example` avec **Load variables from .env file**, remplacer toutes les valeurs sensibles, puis déployer. Une valeur `APP_KEY` peut être générée sur une machine disposant de PHP/Composer avec `php artisan key:generate --show`.

Le déploiement Compose direct reste également disponible :

```sh
cp .env.example .env
./scripts/generate-signing-certificate.sh
sudo install -d -o 33 -g 33 /srv/docker/signature/data /srv/docker/signature/logs
sudo install -d -o 70 -g 70 /srv/docker/signature/postgres
docker compose build
docker compose run --rm --no-deps migrate php artisan key:generate --show
docker compose up -d
```

Recopier la valeur affichée par `key:generate --show` dans `APP_KEY` du fichier `.env`, puis lancer les services. Créer au préalable le réseau externe indiqué par `TRAEFIK_NETWORK`. Les données sont montées sous `/srv/docker/signature/{postgres,data,logs}`. Sauvegarder ensemble PostgreSQL, `data`, le certificat, sa clé privée et `APP_KEY`. Sans `APP_KEY`, les images de signature sauvegardées ne sont plus déchiffrables ; sans la clé du certificat, le même cachet ne peut plus être produit.

L’image applicative tourne sans privilège, avec système de fichiers en lecture seule, capacités Linux supprimées et répertoires temporaires en `tmpfs`.

## Notifications

Le canal e-mail utilise la configuration Laravel `MAIL_*`; `MAIL_FROM_ADDRESS` peut être l’adresse de la boîte partagée. Teams utilise `TEAMS_WEBHOOK_URL`. Telegram utilise `TELEGRAM_BOT_TOKEN` et `TELEGRAM_CHAT_ID`. Les envois sont exécutés par `worker` avec reprises.

## API Acanet v1

Authentification : JWT HS256 dans `Authorization: Bearer`, émetteur `ACANET_JWT_ISSUER` et portée `documents:write`. Générer un jeton de test :

```sh
php artisan acanet:token --ttl=3600
```

Création idempotente par `external_reference` :

```sh
curl -X POST https://signature.acarts.lan/api/v1/documents \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -F "file=@contrat.pdf" \
  -F "title=Contrat" \
  -F "external_reference=RH-2026-0042" \
  -F "requester_email=operateur@acarts.be" \
  -F "signers[0][email]=signataire@acarts.be"
```

Routes disponibles :

- `POST /api/v1/documents`
- `GET /api/v1/documents/{id}`
- `POST /api/v1/documents/{id}/cancel`
- `POST /api/v1/documents/{id}/revoke`
- `GET /api/v1/documents/{id}/download`

Si `ACANET_WEBHOOK_URL` est renseignée, chaque changement est envoyé avec `X-Signature-Timestamp`, `Idempotency-Key` et une signature HMAC `X-Signature`. Le consommateur recalcule `HMAC-SHA256(timestamp + "." + corps JSON, ACANET_WEBHOOK_SECRET)`.

## Exploitation

- `php artisan documents:maintain` expire les demandes, relance à trois jours et purge le contenu PDF après `DOCUMENT_RETENTION_DAYS`.
- Le scheduler l’exécute chaque jour à 02:15.
- Les métadonnées et le journal restent conservés après purge du fichier afin de ne pas rompre la chaîne de preuve. Leur destruction exige une politique d’archivage/chaînage validée avant production.
- `php artisan test` exécute les tests ; `vendor/bin/pint --test` vérifie le style.

## Avant production

- remplacer toutes les valeurs d’exemple et désactiver `APP_DEBUG` ;
- protéger `/verifier/*` par les règles réseau prévues dans Traefik ;
- tester une restauration complète ;
- faire auditer Entra, les secrets, les en-têtes proxy, l’API et le circuit PDF ;
- conserver le certificat interne dans un coffre et, si nécessaire, déployer sa chaîne de confiance sur les postes internes.
