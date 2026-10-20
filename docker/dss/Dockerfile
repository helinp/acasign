# syntax=docker/dockerfile:1
# DSS (Commission européenne) construit depuis la version officielle, avec un service d'horodatage réel.
FROM maven:3.9.16-eclipse-temurin-26 AS build
ADD https://github.com/esig/dss-demonstrations.git#6.5.1 /src
COPY tsp-config.xml /src/dss-demo-webapp/src/main/resources/config/tsp-config.xml
WORKDIR /src
# Même commande que le Dockerfile officiel : la webapp embarque le paquet de l'application autonome,
# qui doit donc être construit dans le même passage (sinon : « dss-standalone-app-package … complete-zip » introuvable).
RUN mvn -q package -pl dss-standalone-app,dss-standalone-app-package,dss-demo-webapp -P quick,linux

FROM tomcat:11.0.27-jdk25-temurin
COPY --from=build /src/dss-demo-webapp/target/dss-demo-webapp-*.war /usr/local/tomcat/webapps/ROOT.war
EXPOSE 8080
