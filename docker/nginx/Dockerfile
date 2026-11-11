# Rail22's web container: nginx with the app's public_html (the static files; the .php ones go to app:9000, the same path there), the site from
# templates/ (the image's envsubst: NGINX_SERVER_NAME); public_html/assets is the shared volume (compose.yaml)
FROM nginx:stable
COPY public_html /srv/rail22/public_html
COPY docker/nginx/locations.conf /etc/nginx/nfn/locations.conf
COPY docker/nginx/http.conf.template /etc/nginx/templates/default.conf.template
RUN rm -f /etc/nginx/conf.d/default.conf
