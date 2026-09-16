# syntax=docker/dockerfile:1
ARG COMPOSER_IMAGE
ARG JCB_IMAGE
FROM ${COMPOSER_IMAGE} AS composer
FROM ${JCB_IMAGE}
USER root
RUN set -eux; \
    test "$(id -u www-data)" = 33; \
    test "$(id -g www-data)" = 33; \
    command -v apache2-foreground; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends openssh-server git unzip ca-certificates; \
    rm -rf /var/lib/apt/lists/* /etc/ssh/ssh_host_*; \
    usermod -l developer -d /home/developer -s /bin/bash www-data; \
    usermod -p '*' developer; \
    mkdir -p /home/developer /etc/ssh/authorized-keys /etc/ssh/host-keys /workspace/build; \
    chown 33:33 /home/developer /workspace/build
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY sshd_config /etc/ssh/sshd_config
COPY start.sh /usr/local/bin/workspace-sshd
RUN chmod 0644 /etc/ssh/sshd_config && chmod 0755 /usr/local/bin/workspace-sshd
EXPOSE 2222
WORKDIR /var/www/html
ENTRYPOINT ["/usr/local/bin/workspace-sshd"]
CMD []
