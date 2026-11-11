# Rail22's image: the app's code, PHP 8.5 (Remi, the packages of install.sh), PHP-FPM and the console; compose.yaml runs it as `app` (PHP-FPM on :9000)
# and as `daemon` (app/v daemon::run). Built locally: docker compose build (the build context is the repository's folder, Dockerfile.dockerignore keeps only the app's files)
FROM almalinux:9

ARG PHPV=85
# the user the app runs as (its files, the volumes'); match the host's when the working tree is bind-mounted (compose.dev.yaml)
ARG NFN_UID=1000
ARG NFN_GID=1000
ARG TZ=UTC

ENV BASE=/srv/rail22 \
    NFN_RUNTIME=docker \
    NFN_CONFIG_DIR=/srv/rail22/config \
    PHP_ETC=/etc/opt/remi/php${PHPV} \
    TZ=${TZ}

# EPEL and Remi, then PHP with the app's extensions and the Database step's drivers (the lists of install.sh: PHP_PACKAGES, PHP_DB_PACKAGES), php85-syspaths (/usr/bin/php for
# app/v and the daemon), gnupg2 (FileDecrypt, the FTP steps' PGP), git, procps (pgrep in the healthcheck), tzdata
RUN dnf -y install epel-release dnf-plugins-core \
 && dnf -y install https://rpms.remirepo.net/enterprise/remi-release-9.rpm \
 && dnf config-manager --set-enabled remi crb \
 && dnf -y install --setopt=install_weak_deps=False \
      php${PHPV} php${PHPV}-runtime php${PHPV}-syspaths php${PHPV}-php-cli php${PHPV}-php-common php${PHPV}-php-fpm php${PHPV}-php-gd php${PHPV}-php-gmp \
      php${PHPV}-php-mbstring php${PHPV}-php-mysqlnd php${PHPV}-php-pdo php${PHPV}-php-process php${PHPV}-php-sodium php${PHPV}-php-tidy php${PHPV}-php-xml \
      php${PHPV}-php-pecl-event php${PHPV}-php-pecl-igbinary php${PHPV}-php-pecl-imap php${PHPV}-php-pecl-mailparse php${PHPV}-php-pecl-mongodb2 \
      php${PHPV}-php-pecl-msgpack php${PHPV}-php-pecl-redis6 php${PHPV}-php-pecl-zip \
      php${PHPV}-php-pgsql php${PHPV}-php-pdo-dblib php${PHPV}-php-pdo-firebird php${PHPV}-php-odbc \
      gnupg2 openssl git procps-ng tzdata \
 && dnf clean all && rm -rf /var/cache/dnf

RUN groupadd -g ${NFN_GID} nfn && useradd -u ${NFN_UID} -g nfn -d ${BASE} -M -s /sbin/nologin nfn

# php.ini: Rail22's (scripts/system/php.ini) with the image's time zone and the app's tmp/; the pool: docker/fpm-pool.conf (TCP :9000, the user nfn)
COPY scripts/system/php.ini ${PHP_ETC}/php.ini
COPY docker/fpm-pool.conf ${PHP_ETC}/php-fpm.d/nfn.conf
RUN sed -i -e "s|^date.timezone = .*|date.timezone = ${TZ}|" -e "s|^upload_tmp_dir = .*|upload_tmp_dir = ${BASE}/tmp|" -e "s|^session.save_path = .*|session.save_path = ${BASE}/var/php/session|" ${PHP_ETC}/php.ini \
 && rm -f ${PHP_ETC}/php-fpm.d/www.conf \
 && sed -i -e "s|^pid = .*|pid = ${BASE}/run/php-fpm.pid|" -e 's|^error_log = .*|error_log = /proc/self/fd/2|' -e 's|^daemonize = .*|daemonize = no|' ${PHP_ETC}/php-fpm.conf

# the code, root's (the app cannot change it; app/pkgs: the submodules as they are in the working tree), then the folders the app writes, nfn's (volumes in compose.yaml)
COPY app ${BASE}/app
COPY public_html ${BASE}/public_html
COPY scripts ${BASE}/scripts
COPY docker/entrypoint.sh /usr/local/bin/nfn-entrypoint
RUN mkdir -p ${BASE}/config ${BASE}/logs ${BASE}/tmp ${BASE}/run ${BASE}/bounces ${BASE}/var/php/session ${BASE}/var/php/wsdlcache ${BASE}/var/php/opcache ${BASE}/public_html/assets \
 && chown -R nfn:nfn ${BASE}/config ${BASE}/logs ${BASE}/tmp ${BASE}/run ${BASE}/bounces ${BASE}/var ${BASE}/public_html/assets \
 && chmod 755 /usr/local/bin/nfn-entrypoint

WORKDIR ${BASE}
USER nfn
EXPOSE 9000
ENTRYPOINT [ "nfn-entrypoint" ]
CMD [ "php-fpm" ]
