#!/bin/sh
docker-compose down --remove-orphans
docker rm -f $(docker ps -aq) 2>/dev/null || true
docker-compose up -d --build
