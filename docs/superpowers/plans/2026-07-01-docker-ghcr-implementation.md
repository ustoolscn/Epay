# Docker GHCR Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Docker-based deployment, GHCR image publishing, and environment-driven external-MySQL configuration for this project.

**Architecture:** Build a single `php:apache` runtime image with Apache rewrite rules matching the current application routes, add a compose file that pulls from GHCR and passes database settings via environment variables, and update `config.php` so container deployments can override DB settings without breaking legacy file-based installs. Keep the installer usable by preserving the existing `$dbconfig` array shape and add repository documentation for build and deployment.

**Tech Stack:** PHP, Apache, Docker, Docker Compose, GitHub Actions, GHCR

---

### Task 1: Add the container runtime

**Files:**
- Create: `D:\Coding\Epay\Dockerfile`
- Create: `D:\Coding\Epay\.dockerignore`
- Create: `D:\Coding\Epay\docker\apache\epay.conf`

- [ ] **Step 1: Add the Docker ignore file**

```gitignore
.git
.github
docs
install/install.lock
docker-compose.yml
.env
.env.*
README.md
IIS.txt
nginx.txt
```

- [ ] **Step 2: Add the Apache site configuration**

```apache
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        Options FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php index.html

        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^([A-Za-z0-9_-]+)\.html$ /index.php?mod=$1 [L,QSA]
        RewriteRule ^pay/(.*)$ /pay.php?s=$1 [L,QSA]
        RewriteRule ^api/(.*)$ /api.php?s=$1 [L,QSA]
        RewriteRule ^doc/([A-Za-z0-9_-]+)\.html$ /index.php?doc=$1 [L,QSA]
    </Directory>

    <Location "/plugins">
        Require all denied
    </Location>

    <Location "/includes">
        Require all denied
    </Location>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
```

- [ ] **Step 3: Add the Dockerfile**

```dockerfile
FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libssl-dev \
        unzip \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache/epay.conf /etc/apache2/sites-available/000-default.conf
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;
```

- [ ] **Step 4: Verify the new runtime files exist**

Run: `Get-ChildItem Dockerfile,.dockerignore,docker\apache\epay.conf`
Expected: all three files are listed

- [ ] **Step 5: Commit**

```bash
git add .dockerignore Dockerfile docker/apache/epay.conf
git commit -m "build: add docker runtime"
```

### Task 2: Make database config environment-aware

**Files:**
- Modify: `D:\Coding\Epay\config.php`

- [ ] **Step 1: Replace the static config with env-aware loading**

```php
<?php
/*数据库配置*/

function epay_env($key, $default = null) {
	$value = getenv($key);
	return $value === false || $value === '' ? $default : $value;
}

$dbconfig=array(
	'host' => epay_env('DB_HOST', 'localhost'), //数据库服务器
	'port' => (int)epay_env('DB_PORT', 3306), //数据库端口
	'user' => epay_env('DB_USER', ''), //数据库用户名
	'pwd' => epay_env('DB_PASSWORD', ''), //数据库密码
	'dbname' => epay_env('DB_NAME', ''), //数据库名
	'dbqz' => epay_env('DB_PREFIX', 'pay') //数据表前缀
);
```

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l config.php`
Expected: `No syntax errors detected in config.php`

- [ ] **Step 3: Verify env override behavior**

Run: `@'$env:DB_HOST="mysql.example.com"; $env:DB_PORT="3307"; $env:DB_NAME="epay"; $env:DB_USER="epay"; $env:DB_PASSWORD="secret"; $env:DB_PREFIX="payx"; include "config.php"; echo json_encode($dbconfig);'@ | php`
Expected: JSON contains `mysql.example.com`, `3307`, `epay`, `payx`

- [ ] **Step 4: Commit**

```bash
git add config.php
git commit -m "feat: support env-based db config"
```

### Task 3: Add compose deployment assets

**Files:**
- Create: `D:\Coding\Epay\docker-compose.yml`
- Create: `D:\Coding\Epay\.env.example`

- [ ] **Step 1: Add the environment example file**

```dotenv
APP_PORT=8080
IMAGE_NAME=ghcr.io/your-github-owner/epay
IMAGE_TAG=latest
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=epay
DB_USER=epay
DB_PASSWORD=change-me
DB_PREFIX=pay
```

- [ ] **Step 2: Add the compose file**

```yaml
services:
  app:
    image: ${IMAGE_NAME}:${IMAGE_TAG}
    container_name: epay-app
    restart: unless-stopped
    ports:
      - "${APP_PORT}:80"
    environment:
      DB_HOST: ${DB_HOST}
      DB_PORT: ${DB_PORT}
      DB_NAME: ${DB_NAME}
      DB_USER: ${DB_USER}
      DB_PASSWORD: ${DB_PASSWORD}
      DB_PREFIX: ${DB_PREFIX}
```

- [ ] **Step 3: Verify compose interpolation**

Run: `docker compose --env-file .env.example config`
Expected: rendered service output shows the `app` service with the configured image, port mapping, and database environment variables

- [ ] **Step 4: Commit**

```bash
git add .env.example docker-compose.yml
git commit -m "ops: add compose deployment files"
```

### Task 4: Add the GHCR publishing workflow

**Files:**
- Create: `D:\Coding\Epay\.github\workflows\docker-image.yml`

- [ ] **Step 1: Add the workflow**

```yaml
name: Build and Publish Docker Image

on:
  push:
    branches:
      - main
      - master
    tags:
      - "*"
  workflow_dispatch:

permissions:
  contents: read
  packages: write

jobs:
  docker:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Log in to GHCR
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Generate timestamp tag
        id: timestamp
        run: echo "value=$(date -u +'%Y%m%d-%H%M')" >> "$GITHUB_OUTPUT"

      - name: Extract image metadata
        id: meta
        uses: docker/metadata-action@v5
        with:
          images: ghcr.io/${{ github.repository }}
          tags: |
            type=raw,value=latest
            type=raw,value=${{ steps.timestamp.outputs.value }}
            type=ref,event=tag

      - name: Build and push
        uses: docker/build-push-action@v6
        with:
          context: .
          push: true
          tags: ${{ steps.meta.outputs.tags }}
          labels: ${{ steps.meta.outputs.labels }}
```

- [ ] **Step 2: Verify workflow YAML parses**

Run: `python -c "import yaml, pathlib; print(yaml.safe_load(pathlib.Path('.github/workflows/docker-image.yml').read_text(encoding='utf-8'))['name'])"`
Expected: `Build and Publish Docker Image`

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/docker-image.yml
git commit -m "ci: publish docker image to ghcr"
```

### Task 5: Update deployment documentation

**Files:**
- Modify: `D:\Coding\Epay\README.md`

- [ ] **Step 1: Add a Docker deployment section**

```markdown
## Docker 部署

### GitHub Actions 自动构建镜像

推送到 `main` 或 `master` 分支后，GitHub Actions 会自动构建镜像并推送到：

`ghcr.io/<你的 GitHub 用户或组织>/<仓库名>`

镜像标签包括：

- `latest`
- `YYYYMMDD-HHmm`
- Git tag 名称（如果本次构建由 Git tag 触发）

首次使用 GHCR 时，请确认仓库 Actions 拥有写入 Packages 的权限。

### 使用 docker-compose 部署

1. 复制环境变量示例文件：

   ```bash
   cp .env.example .env
   ```

2. 修改 `.env` 中的镜像地址和外部 MySQL 参数。

3. 启动服务：

   ```bash
   docker compose pull
   docker compose up -d
   ```

4. 浏览器访问 `http://<服务器IP>:<APP_PORT>/install/` 完成初始化。

### 初始化数据库

- 可以通过安装向导初始化数据库
- 也可以手动导入 `install/install.sql`
- 初始化完成后建议删除或限制 `install` 目录访问
```

- [ ] **Step 2: Verify README still renders as valid Markdown**

Run: `Get-Content README.md -TotalCount 120`
Expected: the new Docker deployment section appears with the commands and GHCR notes

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: add docker deployment guide"
```

### Task 6: End-to-end verification

**Files:**
- Verify: `D:\Coding\Epay\Dockerfile`
- Verify: `D:\Coding\Epay\docker-compose.yml`
- Verify: `D:\Coding\Epay\.github\workflows\docker-image.yml`
- Verify: `D:\Coding\Epay\config.php`
- Verify: `D:\Coding\Epay\README.md`

- [ ] **Step 1: Build the image locally**

Run: `docker build -t epay:test .`
Expected: build succeeds and completes with the Apache PHP image tagged as `epay:test`

- [ ] **Step 2: Verify Apache config in the built image**

Run: `docker run --rm epay:test apachectl -t`
Expected: `Syntax OK`

- [ ] **Step 3: Verify the container starts with external DB environment variables**

Run: `docker run --rm -d --name epay-smoke -p 18080:80 -e DB_HOST=127.0.0.1 -e DB_PORT=3306 -e DB_NAME=epay -e DB_USER=epay -e DB_PASSWORD=secret -e DB_PREFIX=pay epay:test`
Expected: container starts successfully and remains running long enough for smoke checks

- [ ] **Step 4: Verify route and access-control behavior**

Run: `curl -I http://127.0.0.1:18080/`  
Expected: HTTP response from Apache

Run: `curl -I http://127.0.0.1:18080/plugins/`  
Expected: `403 Forbidden`

Run: `curl -I http://127.0.0.1:18080/includes/`  
Expected: `403 Forbidden`

- [ ] **Step 5: Stop the smoke-test container**

Run: `docker rm -f epay-smoke`
Expected: container is removed cleanly

- [ ] **Step 6: Review final diff**

Run: `git status --short`
Expected: only intended implementation files are modified or added

- [ ] **Step 7: Final commit**

```bash
git add .dockerignore .env.example .github/workflows/docker-image.yml Dockerfile README.md config.php docker-compose.yml docker/apache/epay.conf
git commit -m "feat: add docker and ghcr deployment support"
```
