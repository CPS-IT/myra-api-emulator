# The Myra API emulator as a self-contained image: no Composer, no other services.
FROM php:8.4-cli-alpine

LABEL org.opencontainers.image.source="https://github.com/CPS-IT/myra-api-emulator" \
      org.opencontainers.image.description="Emulator of the Myra Cloud API (v1 and v2) for local development and tests" \
      org.opencontainers.image.licenses="MIT"

WORKDIR /opt/myra-api-emulator
COPY src/ src/
COPY public/ public/
COPY fixtures/ fixtures/

# Call log and mode live in /tmp: a new container starts from the fixtures and the account file.
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=6 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8080/_emulator/mode') ? 0 : 1);"

CMD ["php", "-S", "0.0.0.0:8080", "public/index.php"]
