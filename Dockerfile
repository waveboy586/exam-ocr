FROM php:8.2-apache

ENV DEBIAN_FRONTEND=noninteractive \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PYTHONUNBUFFERED=1 \
    PYTHONIOENCODING=UTF-8

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 \
        python3-pip \
        python3-venv \
        python3-dev \
        build-essential \
        libmariadb-dev \
        libmariadb-dev-compat \
        libjpeg-dev \
        zlib1g-dev \
        libpng-dev \
        libfreetype6-dev \
        libssl-dev \
        libffi-dev \
        libmupdf-dev \
        mupdf-tools \
        poppler-utils \
        tesseract-ocr \
        tesseract-ocr-tha \
        tesseract-ocr-eng \
        curl \
        ca-certificates \
        git \
        pkg-config \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite headers

# Create an isolated venv (sidesteps PEP 668 + pip version issues on Bookworm)
RUN python3 -m venv /opt/venv
ENV VIRTUAL_ENV=/opt/venv \
    PATH="/opt/venv/bin:$PATH"

# pip config: longer timeout + retries (PyPI flakiness)
ENV PIP_DEFAULT_TIMEOUT=300 \
    PIP_RETRIES=5

COPY requirements.txt /tmp/requirements.txt

# Upgrade pip in venv
RUN pip install --upgrade pip setuptools wheel

# Install torch CPU-only FIRST (small, avoids pulling CUDA ~2GB)
RUN pip install --index-url https://download.pytorch.org/whl/cpu \
        torch torchvision

# Scientific stack
RUN pip install numpy pandas openpyxl

# OCR / PDF stack
RUN pip install PyMuPDF PyPDF2 pillow

# App-level deps
RUN pip install python-dotenv mysql-connector-python openai langdetect

# NLP stack (sentence-transformers pulls transformers + tokenizers)
RUN pip install sentence-transformers

# Typhoon OCR + qwen utils
RUN pip install typhoon-ocr qwen-vl-utils

# argostranslate — optional, can fail on some architectures; don't block build
RUN pip install argostranslate || \
    echo "[WARN] argostranslate install failed — translation features disabled"

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-custom.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
COPY . /var/www/html

RUN mkdir -p \
        /var/www/html/web/teacher/uploads \
        /var/www/html/web/teacher/storage/logs \
        /var/www/html/web/teacher/storage/outputs \
        /var/www/html/web/teacher/storage/uploads \
        /var/www/html/web/teacher/auto_grade_jobs \
        /var/www/html/web/admin/uploads \
        /var/www/html/web/teacher/manage_uploads \
    && chown -R www-data:www-data /var/www/html

ENV PYTHON_BIN=/opt/venv/bin/python \
    DB_HOST=db \
    DB_NAME=exam_ocr \
    DB_USER=root \
    DB_PASSWORD=rootpass \
    DB_CHARSET=utf8mb4 \
    APACHE_DOCUMENT_ROOT=/var/www/html/web

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
