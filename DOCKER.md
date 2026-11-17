# Docker Guide — Benefit Backend

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running
- `.env` file created from `.env.example` with all values filled in

---

## Services

| Service | Description | Port |
|---|---|---|
| `mysql` | MySQL 8 database | `3308:3306` |
| `app` | Laravel PHP-FPM | internal |
| `migrate` | Runs migrations on startup then exits | — |
| `nginx` | Reverse proxy, exposes the API | `8000:80` |
| `queue` | Laravel queue worker | internal |

---

## Getting Started (First Time)

**1. Copy the environment file and fill in your values:**
```bash
copy .env.example .env
```

**2. Build the image and start all services:**
```bash
docker-compose up -d --build
```

This will automatically:
- Build the `benefit-backend` image from the Dockerfile
- Start MySQL and wait until it is healthy
- Run all pending migrations
- Start the API and queue worker

**3. Verify everything is running:**
```bash
docker-compose ps
```

**4. Access the API:**
```
http://localhost:8000/api/v1
```

---

## Daily Commands

### Start all services
```bash
docker-compose up -d
```
Starts all containers in the background. Does not rebuild the image.

### Stop all services
```bash
docker-compose down
```
Stops and removes all containers. Database volume is preserved.

### Restart all services
```bash
docker-compose restart
```
Restarts all running containers without rebuilding.

### Restart a single service
```bash
docker-compose restart app
docker-compose restart queue
docker-compose restart nginx
```

---

## Rebuilding After Code Changes

### Rebuild and restart everything
```bash
docker-compose down && docker-compose up -d --build
```
Use this whenever you change PHP code, the Dockerfile, or composer dependencies.

### Or use the deploy script
```bash
bash deploy.sh
```
Does the same as above — stops old containers and rebuilds fresh.

---

## Logs

### View logs for all services
```bash
docker-compose logs
```

### Follow live logs for all services
```bash
docker-compose logs -f
```

### View logs for a specific service
```bash
docker-compose logs app
docker-compose logs nginx
docker-compose logs mysql
docker-compose logs migrate
docker-compose logs queue
```

### Follow live logs for a specific service
```bash
docker-compose logs -f app
docker-compose logs -f queue
```

---

## Running Artisan Commands

```bash
docker exec benefit_app php artisan <command>
```

### Common examples
```bash
# Run migrations manually
docker exec benefit_app php artisan migrate

# Rollback last migration
docker exec benefit_app php artisan migrate:rollback

# Clear all caches
docker exec benefit_app php artisan optimize:clear

# Rebuild all caches
docker exec benefit_app php artisan optimize

# List all routes
docker exec benefit_app php artisan route:list

# Open tinker (interactive shell)
docker exec -it benefit_app php artisan tinker
```

---

## Database Access

### Connect via MySQL client inside the container
```bash
docker exec -it benefit_mysql mysql -uroot -p
```
Enter your `DB_PASSWORD` when prompted.

### Connect from a local tool (TablePlus, DBeaver, etc.)
```
Host:     127.0.0.1
Port:     3308
Database: benefit
Username: root
Password: your DB_PASSWORD from .env
```

---

## Cleanup Commands

### Remove all stopped containers
```bash
docker rm $(docker ps -aq)
```

### Force remove all containers (including running ones)
```bash
docker rm -f $(docker ps -aq)
```

### Remove the database volume (WARNING: deletes all data)
```bash
docker-compose down -v
```

### Remove unused images to free disk space
```bash
docker image prune -f
```

---

## Deployment (Production)

### Build and push image to Docker Hub
```bash
docker build -t yourdockerhubuser/benefit-backend:latest .
docker push yourdockerhubuser/benefit-backend:latest
```

### On the production server (pull and run)
```bash
# Only needs docker-compose.yml and .env on the server
docker-compose pull
docker-compose up -d
```

### Update production to latest image
```bash
docker-compose pull
docker-compose down
docker-compose up -d
```

---

## Troubleshooting

### Containers conflict (name already in use)
```bash
docker rm -f $(docker ps -aq)
docker-compose up -d --build
```

### Migrations failed
```bash
# Check what went wrong
docker logs benefit_migrate

# Re-run migrations manually
docker exec benefit_app php artisan migrate --force
```

### App not responding
```bash
# Check if all containers are running
docker-compose ps

# Check app logs for errors
docker-compose logs app
docker-compose logs nginx
```

### Permission errors on storage
```bash
docker exec benefit_app chmod -R 775 /var/www/html/storage
docker exec benefit_app chown -R www-data:www-data /var/www/html/storage
```
